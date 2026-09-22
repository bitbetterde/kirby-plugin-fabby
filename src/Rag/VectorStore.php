<?php

namespace Fabby\Rag;

use SQLite3;

/**
 * Stores chunk embeddings and answers nearest-neighbour queries.
 *
 * Both search paths live in here so callers never branch on which one is
 * active. fabby_chunks always holds the vectors; the vec0 virtual table is a
 * derived accelerator mirroring them. That split is what makes "same schema,
 * same results" true: a server that loses the extension — php.ini reverted,
 * PHP upgraded, host migrated — degrades to the PHP path with identical
 * results rather than owning a database it cannot read.
 *
 * The FTS5 index (fabby_fts) is derived the same way, but its guarantee is
 * weaker on purpose, and the difference is worth stating: sqlite-vec is purely
 * an accelerator and both vector paths return the same hits, whereas FTS5 is
 * an accelerator for lexical candidate discovery. Its broad prefix results
 * are always validated by the same conservative matcher as the PHP fallback,
 * so enabling FTS5 cannot silently change which words count as matches.
 *
 * Vectors are stored NORMALISED. Cosine similarity then collapses to a plain
 * dot product, which removes two sqrt calls and a division per candidate from
 * the hot loop, and makes vec0's L2 distance monotonically equivalent to
 * cosine — the property the two paths need in order to agree.
 *
 * Uses SQLite3 rather than PDO because only SQLite3 can load extensions;
 * Kirby\Database\Database wraps PDO and cannot.
 */
final class VectorStore
{
    /** 4 makes the sqlite-vec table an active-fingerprint-only projection. */
    private const SCHEMA_VERSION = 4;

    /** Version 2 introduced the derived full-text index. */
    private const FTS_SCHEMA_VERSION = 2;

    /** Standard reciprocal-rank constant; equal weight for both result lists. */
    private const RRF_K = 60;

    /**
     * Candidate surplus per requested hit, and the bounds it is clamped to.
     *
     * Two losses eat into a result set and both are covered by the same
     * surplus: lexical re-ranking has to be able to pull a candidate forward
     * from behind the cut, and the caller drops withdrawn pages afterwards and
     * needs replacements from further down. The floor keeps the pool useful for
     * a small top-K, the ceiling keeps it from becoming a full scan.
     */
    private const CANDIDATE_OVERSAMPLE = 4;
    private const CANDIDATE_FLOOR = 20;
    private const CANDIDATE_CEILING = 100;

    /** Shorter stems create too many coincidental German prefix matches. */
    private const INFLECTION_MIN_STEM_LENGTH = 6;

    /** Deliberately small German suffix set; compounds are not inflections. */
    private const INFLECTION_SUFFIXES = ['e', 'en', 'er', 'ern', 'es', 'n', 's'];

    /** Common German query words that carry no useful retrieval signal. */
    private const STOP_WORDS = [
        'aber', 'alle', 'als', 'also', 'am', 'an', 'auch', 'auf', 'aus',
        'bei', 'bin', 'bis', 'das', 'dem', 'den', 'der', 'des', 'die',
        'ein', 'eine', 'einer', 'eines', 'für', 'hat', 'ich', 'im', 'in',
        'ist', 'mit', 'nach', 'nicht', 'oder', 'sich', 'sind', 'über', 'um',
        'und', 'vom', 'von', 'vor', 'was', 'welche', 'welcher', 'welches',
        'werden', 'wie', 'wo', 'zu', 'zum', 'zur',
    ];

    private ?SQLite3 $db = null;

    /** @var array{status: string, reason: string, version: string|null, path: string}|null */
    private ?array $vec = null;

    /** @var array{status: string, reason: string}|null */
    private ?array $fts = null;

    public function __construct(
        private readonly string $dbPath,
        private readonly string $vecFile = '',
        private readonly int $dims = 1536,
        private readonly string $indexFingerprint = '',
    ) {
    }

    public function workerLockPath(): string
    {
        if ($this->dbPath === ':memory:') {
            return sys_get_temp_dir() . '/fabby-rag-worker-' . spl_object_id($this) . '.lock';
        }

        return dirname($this->dbPath) . '/.worker.lock';
    }

    public function connect(): void
    {
        if ($this->db !== null) {
            return;
        }

        if ($this->dbPath !== ':memory:') {
            $dir = dirname($this->dbPath);

            if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Verzeichnis für die RAG-Datenbank nicht anlegbar: ' . $dir);
            }
        }

        $db = new SQLite3($this->dbPath);
        $db->enableExceptions(true);
        $db->busyTimeout(5000);

        // WAL lets a chat request read while a Panel save writes.
        if ($this->dbPath !== ':memory:') {
            @$db->exec('PRAGMA journal_mode = WAL');
        }

        @$db->exec('PRAGMA synchronous = NORMAL');

        $this->db = $db;
        $this->vec = (new SqliteVecLoader($this->vecFile))->tryLoad($db);
        $this->fts = (new Fts5Probe())->probe($db);

        $this->migrate();
    }

    public function close(): void
    {
        $this->db?->close();
        $this->db = null;
        $this->vec = null;
        $this->fts = null;
    }

    public function vecAvailable(): bool
    {
        return ($this->vec['status'] ?? '') === SqliteVecLoader::STATUS_ACTIVE;
    }

    public function ftsAvailable(): bool
    {
        return ($this->fts['status'] ?? '') === Fts5Probe::STATUS_ACTIVE;
    }

    /** @return array{status: string, reason: string, version: string|null, path: string} */
    public function vecDiagnostics(): array
    {
        $this->connect();

        return $this->vec ?? ['status' => 'unknown', 'reason' => '', 'version' => null, 'path' => ''];
    }

    /** @return array{status: string, reason: string} */
    public function ftsDiagnostics(): array
    {
        $this->connect();

        return $this->fts ?? ['status' => 'unknown', 'reason' => ''];
    }

    // --- writing ------------------------------------------------------------

    /**
     * Replaces every chunk of one page in a single transaction.
     *
     * Delete-then-insert rather than update: the chunk count changes between
     * revisions, and this is the only formulation correct for both growth and
     * shrinkage.
     *
     * @param string[]  $chunks
     * @param float[][] $vectors One vector per chunk, same order.
     */
    public function replacePage(
        string $pageKey,
        string $pageId,
        string $url,
        string $title,
        array $chunks,
        array $vectors,
        string $contentHash,
        int $modified,
        string $model,
    ): void {
        $this->connect();

        if (count($chunks) !== count($vectors)) {
            throw new \InvalidArgumentException('Chunk- und Vektoranzahl stimmen nicht überein.');
        }

        $this->db->exec('BEGIN IMMEDIATE');

        try {
            $this->deletePageRows($pageKey);

            $insert = $this->db->prepare(
                'INSERT INTO fabby_chunks
                    (page_key, page_id, url, title, chunk_index, chunk_text, embedding,
                     dims, embedding_model, index_fingerprint, content_hash, page_modified, indexed_at)
                 VALUES (:key, :id, :url, :title, :idx, :text, :emb,
                         :dims, :model, :fingerprint, :hash, :modified, :now)'
            );

            $now = time();

            foreach ($chunks as $i => $chunk) {
                $vector = self::normalize($vectors[$i]);

                $insert->reset();
                $insert->bindValue(':key', $pageKey, SQLITE3_TEXT);
                $insert->bindValue(':id', $pageId, SQLITE3_TEXT);
                $insert->bindValue(':url', $url, SQLITE3_TEXT);
                $insert->bindValue(':title', $title, SQLITE3_TEXT);
                $insert->bindValue(':idx', $i, SQLITE3_INTEGER);
                $insert->bindValue(':text', $chunk, SQLITE3_TEXT);
                $insert->bindValue(':emb', self::pack($vector), SQLITE3_BLOB);
                $insert->bindValue(':dims', count($vector), SQLITE3_INTEGER);
                $insert->bindValue(':model', $model, SQLITE3_TEXT);
                $insert->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);
                $insert->bindValue(':hash', $contentHash, SQLITE3_TEXT);
                $insert->bindValue(':modified', $modified, SQLITE3_INTEGER);
                $insert->bindValue(':now', $now, SQLITE3_INTEGER);
                $insert->execute();

                $rowId = $this->db->lastInsertRowID();

                if ($this->vecAvailable()) {
                    $this->mirrorToVec($rowId, $vector);
                }

                if ($this->ftsAvailable()) {
                    $this->mirrorToFts($rowId, $title, $chunk);
                }
            }

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');

            throw $e;
        }
    }

    public function deletePage(string $pageKey): void
    {
        $this->connect();

        $this->db->exec('BEGIN IMMEDIATE');

        try {
            $this->deletePageRows($pageKey);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');

            throw $e;
        }
    }

    public function contentHashFor(string $pageKey): ?string
    {
        $this->connect();

        $stmt = $this->db->prepare('SELECT content_hash FROM fabby_chunks WHERE page_key = :key LIMIT 1');
        $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        return $row === false ? null : (string) $row['content_hash'];
    }

    /**
     * Refreshes citation metadata without touching chunks or embeddings.
     * This is the zero-cost path for slug/move changes with a stable UUID.
     */
    public function updatePageMetadata(
        string $pageKey,
        string $pageId,
        string $url,
        string $title,
        int $modified,
    ): void {
        $this->connect();

        $this->db->exec('BEGIN IMMEDIATE');

        try {
            // The index covers `title`, so a renamed page needs its rows
            // re-indexed. Easy to miss, because nothing here touches chunk
            // text or embeddings — and SQLite's own 'integrity-check' does
            // NOT report a title left stale this way.
            //
            // Retraction has to happen while the old values are still
            // readable; the re-insert then uses the new title.
            $rows = [];

            if ($this->ftsAvailable()) {
                $select = $this->db->prepare(
                    'SELECT id, title, chunk_text FROM fabby_chunks WHERE page_key = :key'
                );
                $select->bindValue(':key', $pageKey, SQLITE3_TEXT);
                $result = $select->execute();

                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $rows[] = $row;
                    $this->unmirrorFromFts(
                        (int) $row['id'],
                        (string) $row['title'],
                        (string) $row['chunk_text']
                    );
                }
            }

            $stmt = $this->db->prepare(
                'UPDATE fabby_chunks
                 SET page_id = :id, url = :url, title = :title, page_modified = :modified
                 WHERE page_key = :key'
            );
            $stmt->bindValue(':id', $pageId, SQLITE3_TEXT);
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $stmt->bindValue(':title', $title, SQLITE3_TEXT);
            $stmt->bindValue(':modified', $modified, SQLITE3_INTEGER);
            $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
            $stmt->execute();

            foreach ($rows as $row) {
                $this->mirrorToFts(
                    (int) $row['id'],
                    $title,
                    (string) $row['chunk_text']
                );
            }

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');

            throw $e;
        }
    }

    public function truncate(): void
    {
        $this->connect();

        // Before the chunks go: with external content, 'delete-all' is the
        // command that empties the index without reading rows that are about
        // to disappear.
        if ($this->ftsAvailable()) {
            @$this->db->exec("INSERT INTO fabby_fts(fabby_fts) VALUES('delete-all')");
        }

        $this->db->exec('DELETE FROM fabby_chunks');

        if ($this->vecAvailable()) {
            @$this->db->exec('DELETE FROM fabby_vec');
        }
    }

    // --- reading ------------------------------------------------------------

    /**
     * How many candidates to consider to return `$topK` usable hits.
     *
     * Public and static because the caller needs the same number to size its
     * own request: KnowledgeSearchTool filters withdrawn pages after the fact,
     * so it asks for the surplus rather than for top_k. Two copies of the
     * formula would have to stay in step without knowing about each other.
     */
    public static function candidatePoolSize(int $topK): int
    {
        return min(
            self::CANDIDATE_CEILING,
            max(self::CANDIDATE_FLOOR, $topK * self::CANDIDATE_OVERSAMPLE)
        );
    }

    /**
     * @param float[] $queryVector
     * @return SearchHit[] Ordered by descending similarity.
     */
    public function search(array $queryVector, string $model, int $topK = 5, float $minSimilarity = 0.0): array
    {
        $this->connect();

        if ($queryVector === [] || $topK < 1) {
            return [];
        }

        $query = self::normalize($queryVector);

        $hits = $this->vecAvailable()
            ? $this->searchWithVec($query, $model, $topK)
            : $this->searchBruteForce($query, $model, $topK);

        return array_values(array_filter(
            $hits,
            static fn (SearchHit $hit): bool => $hit->similarity >= $minSimilarity
        ));
    }

    /**
     * Combines independent semantic and lexical rankings with reciprocal rank
     * fusion. The configured threshold remains a semantic admission gate;
     * validated lexical hits may enter independently.
     *
     * Embeddings are intentionally fuzzy, which is useful for broad questions
     * but can rank a rare proper noun below generic, semantically related
     * pages. The lexical pass promotes matching terms from both the semantic
     * candidate pool and a cheap text-only scan. It tolerates short German
     * inflection suffixes (`Pfandbecher`/`Pfandbechern`) without applying an
     * aggressive language stemmer that would create unrelated matches.
     *
     * @param float[]  $queryVector
     * @param int      $topK  Hits to return; also sizes the candidate pool.
     * @param int|null $limit Return this many instead of `$topK`, for a caller
     *                        that filters afterwards and needs replacements.
     * @return SearchHit[] Ordered by descending hybrid relevance.
     */
    public function hybridSearch(
        array $queryVector,
        string $queryText,
        string $model,
        int $topK = 5,
        float $minSimilarity = 0.0,
        ?int $limit = null,
    ): array {
        $this->connect();

        if ($queryVector === [] || $topK < 1) {
            return [];
        }

        $wanted = max($topK, $limit ?? $topK);
        $tokens = self::meaningfulTokens($queryText);

        if ($tokens === []) {
            return $this->search($queryVector, $model, $wanted, $minSimilarity);
        }

        // search() normalises its own argument, so the raw vector goes there.
        // Only the extra candidates found lexically below need a unit vector,
        // because their cosine is computed here rather than by search().
        // Normalising twice was harmless (idempotent to 1.1e-16), which is why
        // it went unnoticed — but it made the reader verify that.
        $unit = self::normalize($queryVector);
        $poolSize = self::candidatePoolSize($wanted);
        $candidates = [];

        $semanticRank = 0;

        foreach ($this->search($queryVector, $model, $poolSize, $minSimilarity) as $hit) {
            $key = self::hitKey($hit);
            $candidates[$key] = [
                'hit' => $hit,
                'cosine' => $hit->similarity,
                'lexical' => self::lexicalCoverage($tokens, $hit->title . "\n" . $hit->text),
                'bm25' => INF,
                'semantic_rank' => ++$semanticRank,
            ];
        }

        // The right exact-term result is not guaranteed to be in even a wide
        // semantic pool, so look for the terms themselves as well.
        $lexicalRows = $this->lexicalCandidates(
            $tokens,
            $model,
            count($queryVector),
            $poolSize
        );
        $vectorStmt = $this->db->prepare(
            'SELECT embedding FROM fabby_chunks WHERE id = :id LIMIT 1'
        );

        foreach ($lexicalRows as $entry) {
            $row = $entry['row'];
            $key = (string) $row['page_key'] . "\0" . (int) $row['chunk_index'];

            if (isset($candidates[$key])) {
                $candidates[$key]['lexical'] = max(
                    $candidates[$key]['lexical'],
                    $entry['lexical']
                );
                $candidates[$key]['bm25'] = min($candidates[$key]['bm25'], $entry['bm25']);
                continue;
            }

            $vectorStmt->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
            $vectorResult = $vectorStmt->execute();
            $vectorRow = $vectorResult->fetchArray(SQLITE3_ASSOC);

            if ($vectorRow === false) {
                continue;
            }

            $cosine = self::dot($unit, self::unpack((string) $vectorRow['embedding']));
            $hit = new SearchHit(
                pageId: (string) $row['page_id'],
                url: (string) $row['url'],
                title: (string) $row['title'],
                text: (string) $row['chunk_text'],
                chunkIndex: (int) $row['chunk_index'],
                similarity: $cosine,
                pageKey: (string) $row['page_key'],
            );

            $candidates[$key] = [
                'hit' => $hit,
                'cosine' => $cosine,
                'lexical' => $entry['lexical'],
                'bm25' => $entry['bm25'],
                'semantic_rank' => null,
            ];
        }

        $lexical = array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['lexical'] > 0.0,
        );
        uasort($lexical, static function (array $a, array $b): int {
            return ($b['lexical'] <=> $a['lexical'])
                ?: ($a['bm25'] <=> $b['bm25'])
                ?: ($b['cosine'] <=> $a['cosine'])
                ?: (self::hitKey($a['hit']) <=> self::hitKey($b['hit']));
        });

        $lexicalRanks = [];
        $rank = 0;

        foreach (array_keys($lexical) as $key) {
            $lexicalRanks[$key] = ++$rank;
        }

        $ranked = [];

        foreach ($candidates as $key => $candidate) {
            $score = ($candidate['semantic_rank'] === null
                    ? 0.0
                    : 1.0 / (self::RRF_K + $candidate['semantic_rank']))
                + (!isset($lexicalRanks[$key])
                    ? 0.0
                    : 1.0 / (self::RRF_K + $lexicalRanks[$key]));
            $ranked[] = [
                'hit' => $candidate['hit'],
                'score' => $score,
                'lexical' => $candidate['lexical'],
                'cosine' => $candidate['cosine'],
                'key' => $key,
            ];
        }

        usort($ranked, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score'])
                ?: ($b['lexical'] <=> $a['lexical'])
                ?: ($b['cosine'] <=> $a['cosine'])
                ?: ($a['key'] <=> $b['key']);
        });

        return array_map(
            static fn (array $entry): SearchHit => $entry['hit'],
            array_slice($ranked, 0, $wanted)
        );
    }

    /**
     * Finds chunks containing the query's terms, best coverage first.
     *
     * Both paths use the same matcher and coverage scale. FTS narrows the rows
     * to inspect and supplies BM25 only as a tie-breaker.
     *
     * @param string[] $tokens
     * @return array<array{row: array<string,mixed>, lexical: float, bm25: float}>
     */
    private function lexicalCandidates(
        array $tokens,
        string $model,
        int $dims,
        int $poolSize,
    ): array {
        $rows = $this->ftsAvailable()
            ? $this->lexicalWithFts($tokens, $model, $dims, $poolSize)
            : $this->lexicalBruteForce($tokens, $model, $dims);

        usort($rows, static function (array $a, array $b): int {
            return ($b['lexical'] <=> $a['lexical'])
                ?: ($a['bm25'] <=> $b['bm25'])
                ?: ((int) $a['row']['id'] <=> (int) $b['row']['id']);
        });

        return array_slice($rows, 0, $poolSize);
    }

    /**
     * FTS is only a broad candidate generator. Prefix hits are validated with
     * lexicalCoverage(), preventing `Messe` from crediting `Messer`.
     *
     * @param string[] $tokens
     * @return array<array{row: array<string,mixed>, lexical: float, bm25: float}>
     */
    private function lexicalWithFts(array $tokens, string $model, int $dims, int $poolSize): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.page_key, c.page_id, c.url, c.title, c.chunk_text, c.chunk_index,
                    bm25(fabby_fts, 2.0, 1.0) AS lexical_rank
             FROM fabby_fts f
             JOIN fabby_chunks c ON c.id = f.rowid
             WHERE fabby_fts MATCH :term
               AND c.embedding_model = :model
               AND c.dims = :dims
               AND c.index_fingerprint = :fingerprint
             LIMIT :limit'
        );

        // A common term sits on many chunks, and cutting the per-term list too
        // early could drop the row that the remaining terms would have pushed
        // to the top. Generous on purpose; the pool is bounded afterwards.
        $perTermLimit = $poolSize * 4;
        $rows = [];

        foreach ($tokens as $token) {
            foreach (self::ftsTerms($token) as $term) {
                $stmt->reset();
                $stmt->bindValue(':term', $term, SQLITE3_TEXT);
                $stmt->bindValue(':model', $model, SQLITE3_TEXT);
                $stmt->bindValue(':dims', $dims, SQLITE3_INTEGER);
                $stmt->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);
                $stmt->bindValue(':limit', $perTermLimit, SQLITE3_INTEGER);
                $result = $stmt->execute();

                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $id = (int) $row['id'];

                    if (!isset($rows[$id]) || (float) $row['lexical_rank'] < (float) $rows[$id]['lexical_rank']) {
                        $rows[$id] = $row;
                    }
                }
            }
        }

        $candidates = [];

        foreach ($rows as $row) {
            $coverage = self::lexicalCoverage(
                $tokens,
                (string) $row['title'] . "\n" . (string) $row['chunk_text'],
            );

            if ($coverage > 0.0) {
                $candidates[] = [
                    'row' => $row,
                    'lexical' => $coverage,
                    'bm25' => (float) $row['lexical_rank'],
                ];
            }
        }

        return $candidates;
    }

    /**
     * Quotes one token into a prefix term.
     *
     * Never interpolate user text into MATCH: FTS5 reads it as query syntax,
     * so `werkstatt OR`, `-pfand` or a lone quote raise an exception that
     * would surface to the model as "the knowledge base could not be
     * searched". Quoting turns every token into a literal. The trailing `*`
     * deliberately over-fetches candidates; lexicalCoverage() rejects
     * unrelated compounds and shared prefixes afterwards.
     */
    private static function ftsTerm(string $token): string
    {
        return '"' . str_replace('"', '""', $token) . '"*';
    }

    /** @return string[] */
    private static function ftsTerms(string $token): array
    {
        $folded = self::foldToken($token);
        $terms = [self::ftsTerm($folded)];

        foreach (self::INFLECTION_SUFFIXES as $suffix) {
            if (!str_ends_with($folded, $suffix)) {
                continue;
            }

            $stemLength = mb_strlen($folded, 'UTF-8') - mb_strlen($suffix, 'UTF-8');

            if ($stemLength >= self::INFLECTION_MIN_STEM_LENGTH) {
                $terms[] = self::ftsTerm(mb_substr($folded, 0, $stemLength, 'UTF-8'));
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Scans chunk text in PHP — correct, but linear in corpus size.
     *
     * Measured with sqlite-vec active: 27.7ms/500, 92.6ms/2000, 240.7ms/5000
     * chunks, against 3ms for the vector search alone. This is why the FTS5
     * path exists; this one remains for a SQLite build without it.
     *
     * @param string[] $tokens
     * @return array<array{row: array<string,mixed>, lexical: float, bm25: float}>
     */
    private function lexicalBruteForce(array $tokens, string $model, int $dims): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, page_key, page_id, url, title, chunk_text, chunk_index
             FROM fabby_chunks
             WHERE embedding_model = :model AND dims = :dims
               AND index_fingerprint = :fingerprint'
        );
        $stmt->bindValue(':model', $model, SQLITE3_TEXT);
        $stmt->bindValue(':dims', $dims, SQLITE3_INTEGER);
        $stmt->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);
        $result = $stmt->execute();
        $rows = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $coverage = self::lexicalCoverage(
                $tokens,
                (string) $row['title'] . "\n" . (string) $row['chunk_text']
            );

            if ($coverage > 0.0) {
                $rows[] = ['row' => $row, 'lexical' => $coverage, 'bm25' => INF];
            }
        }

        return $rows;
    }

    /**
     * @param float[] $query
     * @return SearchHit[]
     */
    private function searchWithVec(array $query, string $model, int $topK): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.page_key, c.page_id, c.url, c.title, c.chunk_text, c.chunk_index, v.distance
             FROM fabby_vec v
             JOIN fabby_chunks c ON c.id = v.chunk_id
             WHERE v.embedding MATCH :vec AND k = :k
               AND c.embedding_model = :model
               AND c.index_fingerprint = :fingerprint
             ORDER BY v.distance'
        );

        $stmt->bindValue(':vec', self::pack($query), SQLITE3_BLOB);
        // fabby_vec contains only the active fingerprint and dimensions.
        $stmt->bindValue(':k', $topK, SQLITE3_INTEGER);
        $stmt->bindValue(':model', $model, SQLITE3_TEXT);
        $stmt->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);

        $result = $stmt->execute();
        $hits = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Unit vectors: cosine = 1 - l2^2 / 2. Converting here means the
            // minSimilarity threshold means the same number on both paths.
            $distance = (float) $row['distance'];

            $hits[] = new SearchHit(
                pageId: (string) $row['page_id'],
                url: (string) $row['url'],
                title: (string) $row['title'],
                text: (string) $row['chunk_text'],
                chunkIndex: (int) $row['chunk_index'],
                similarity: 1.0 - ($distance * $distance) / 2.0,
                pageKey: (string) $row['page_key'],
            );

            if (count($hits) >= $topK) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Brute-force cosine in PHP. Measured at 8ms/500, 35ms/2000, 86ms/5000
     * chunks at 1536 dimensions — entirely adequate at this site's scale.
     *
     * Keeps a bounded top-K rather than sorting everything: with a small fixed
     * K, insertion into a K-sized list beats an N log N sort and holds peak
     * memory to K rows.
     *
     * @param float[] $query
     * @return SearchHit[]
     */
    private function searchBruteForce(array $query, string $model, int $topK): array
    {
        $stmt = $this->db->prepare(
            'SELECT page_key, page_id, url, title, chunk_text, chunk_index, embedding
             FROM fabby_chunks
             WHERE embedding_model = :model AND dims = :dims
               AND index_fingerprint = :fingerprint'
        );
        $stmt->bindValue(':model', $model, SQLITE3_TEXT);
        $stmt->bindValue(':dims', count($query), SQLITE3_INTEGER);
        $stmt->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);

        $result = $stmt->execute();
        $best = [];
        $worst = -INF;

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $similarity = self::dot($query, self::unpack($row['embedding']));

            if (count($best) >= $topK && $similarity <= $worst) {
                continue;
            }

            $best[] = ['s' => $similarity, 'row' => $row];

            usort($best, static fn (array $a, array $b): int => $b['s'] <=> $a['s']);

            if (count($best) > $topK) {
                array_pop($best);
            }

            $worst = $best[count($best) - 1]['s'];
        }

        return array_map(
            static fn (array $e): SearchHit => new SearchHit(
                pageId: (string) $e['row']['page_id'],
                url: (string) $e['row']['url'],
                title: (string) $e['row']['title'],
                text: (string) $e['row']['chunk_text'],
                chunkIndex: (int) $e['row']['chunk_index'],
                similarity: $e['s'],
                pageKey: (string) $e['row']['page_key'],
            ),
            $best
        );
    }

    /** @return array<array{page_key: string, page_id: string}> */
    public function indexedPages(): array
    {
        $this->connect();

        $result = $this->db->query(
            'SELECT page_key, MIN(page_id) AS page_id
             FROM fabby_chunks
             GROUP BY page_key
             ORDER BY page_key'
        );
        $pages = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $pages[] = [
                'page_key' => (string) $row['page_key'],
                'page_id' => (string) $row['page_id'],
            ];
        }

        return $pages;
    }

    /**
     * @return array{
     *   chunks: int, pages: int, compatible_chunks: int, compatible_pages: int,
     *   models: string[], dimensions: int[], fingerprints: string[]
     * }
     */
    public function stats(): array
    {
        $this->connect();

        $row = $this->db->querySingle(
            'SELECT COUNT(*) AS chunks, COUNT(DISTINCT page_key) AS pages FROM fabby_chunks',
            true
        );

        $models = [];
        $result = $this->db->query('SELECT DISTINCT embedding_model FROM fabby_chunks');

        while ($m = $result->fetchArray(SQLITE3_ASSOC)) {
            $models[] = (string) $m['embedding_model'];
        }

        $dimensions = [];
        $result = $this->db->query('SELECT DISTINCT dims FROM fabby_chunks ORDER BY dims');

        while ($dimension = $result->fetchArray(SQLITE3_ASSOC)) {
            $dimensions[] = (int) $dimension['dims'];
        }

        $compatible = $this->db->prepare(
            'SELECT COUNT(*) AS chunks, COUNT(DISTINCT page_key) AS pages
             FROM fabby_chunks WHERE index_fingerprint = :fingerprint'
        );
        $compatible->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);
        $compatibleRow = $compatible->execute()->fetchArray(SQLITE3_ASSOC);

        $fingerprints = [];
        $result = $this->db->query('SELECT DISTINCT index_fingerprint FROM fabby_chunks');

        while ($fingerprint = $result->fetchArray(SQLITE3_ASSOC)) {
            $fingerprints[] = (string) $fingerprint['index_fingerprint'];
        }

        return [
            'chunks' => (int) ($row['chunks'] ?? 0),
            'pages' => (int) ($row['pages'] ?? 0),
            'compatible_chunks' => (int) ($compatibleRow['chunks'] ?? 0),
            'compatible_pages' => (int) ($compatibleRow['pages'] ?? 0),
            'models' => $models,
            'dimensions' => $dimensions,
            'fingerprints' => $fingerprints,
        ];
    }

    /** Unix timestamp of the most recently written embedding, if any. */
    public function lastIndexedAt(): ?int
    {
        $this->connect();

        $value = $this->db->querySingle('SELECT MAX(indexed_at) FROM fabby_chunks');

        return $value === null ? null : (int) $value;
    }

    public function meta(string $key, ?string $default = null): ?string
    {
        $this->connect();

        $stmt = $this->db->prepare('SELECT value FROM fabby_meta WHERE key = :k');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        return $row === false ? $default : (string) $row['value'];
    }

    public function setMeta(string $key, string $value): void
    {
        $this->connect();

        $stmt = $this->db->prepare(
            'INSERT INTO fabby_meta (key, value) VALUES (:k, :v)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', $value, SQLITE3_TEXT);
        $stmt->execute();
    }

    /** Escape hatch for IndexQueue, which shares this connection. */
    public function connection(): SQLite3
    {
        $this->connect();

        return $this->db;
    }

    // --- internals ----------------------------------------------------------

    /**
     * Both mirrors are maintained here, before the rows go away.
     *
     * fabby_fts stores no copy of the text, so its 'delete' command has to be
     * handed the values it indexed — after the DELETE they are unreadable and
     * the index would keep pointing at rows that no longer exist.
     */
    private function deletePageRows(string $pageKey): void
    {
        if ($this->vecAvailable() || $this->ftsAvailable()) {
            $ids = $this->db->prepare(
                'SELECT id, title, chunk_text FROM fabby_chunks WHERE page_key = :key'
            );
            $ids->bindValue(':key', $pageKey, SQLITE3_TEXT);
            $result = $ids->execute();

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($this->vecAvailable()) {
                    $del = $this->db->prepare('DELETE FROM fabby_vec WHERE chunk_id = :id');
                    $del->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
                    $del->execute();
                }

                if ($this->ftsAvailable()) {
                    $this->unmirrorFromFts(
                        (int) $row['id'],
                        (string) $row['title'],
                        (string) $row['chunk_text']
                    );
                }
            }
        }

        $stmt = $this->db->prepare('DELETE FROM fabby_chunks WHERE page_key = :key');
        $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
        $stmt->execute();
    }

    /** @param float[] $vector */
    private function mirrorToVec(int $chunkId, array $vector): void
    {
        $stmt = $this->db->prepare('INSERT INTO fabby_vec (chunk_id, embedding) VALUES (:id, :emb)');
        $stmt->bindValue(':id', $chunkId, SQLITE3_INTEGER);
        $stmt->bindValue(':emb', self::pack($vector), SQLITE3_BLOB);
        $stmt->execute();
    }

    private function mirrorToFts(int $chunkId, string $title, string $text): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO fabby_fts (rowid, title, chunk_text) VALUES (:id, :title, :text)'
        );
        $stmt->bindValue(':id', $chunkId, SQLITE3_INTEGER);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':text', $text, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Retracts one row from the index.
     *
     * The values have to be the ones that were indexed; FTS5 uses them to
     * find and remove the terms it derived. Passing anything else corrupts the
     * index rather than failing loudly.
     */
    private function unmirrorFromFts(int $chunkId, string $title, string $text): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO fabby_fts (fabby_fts, rowid, title, chunk_text)
             VALUES ('delete', :id, :title, :text)"
        );
        $stmt->bindValue(':id', $chunkId, SQLITE3_INTEGER);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':text', $text, SQLITE3_TEXT);
        $stmt->execute();
    }

    private function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS fabby_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_key TEXT NOT NULL,
                page_id TEXT NOT NULL,
                url TEXT NOT NULL,
                title TEXT NOT NULL,
                chunk_index INTEGER NOT NULL,
                chunk_text TEXT NOT NULL,
                embedding BLOB NOT NULL,
                dims INTEGER NOT NULL,
                embedding_model TEXT NOT NULL,
                index_fingerprint TEXT NOT NULL DEFAULT \'\',
                content_hash TEXT NOT NULL,
                page_modified INTEGER NOT NULL,
                indexed_at INTEGER NOT NULL,
                UNIQUE (page_key, chunk_index)
            )'
        );

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_chunks_key ON fabby_chunks (page_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_chunks_model ON fabby_chunks (embedding_model)');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS fabby_queue (
                page_key TEXT PRIMARY KEY,
                page_id TEXT NOT NULL,
                operation TEXT NOT NULL,
                enqueued_at INTEGER NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                revision INTEGER NOT NULL DEFAULT 1,
                last_error TEXT
            )'
        );

        $this->db->exec('CREATE TABLE IF NOT EXISTS fabby_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $this->migrateColumns();
        $fromVersion = (int) $this->db->querySingle('PRAGMA user_version');

        $this->migrateVec();

        $this->migrateFts($fromVersion);
        $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /** Additive migrations preserve queued work and legacy embeddings as unknown. */
    private function migrateColumns(): void
    {
        if (!$this->hasColumn('fabby_queue', 'revision')) {
            $this->db->exec('ALTER TABLE fabby_queue ADD COLUMN revision INTEGER NOT NULL DEFAULT 1');
        }

        if (!$this->hasColumn('fabby_chunks', 'index_fingerprint')) {
            $this->db->exec(
                "ALTER TABLE fabby_chunks ADD COLUMN index_fingerprint TEXT NOT NULL DEFAULT ''"
            );
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $result = $this->db->query('PRAGMA table_info(' . $table . ')');

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ((string) $row['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * sqlite-vec is an expendable projection of only the active index. Keeping
     * incompatible rows out of it matters because vec0 applies k before the
     * relational fingerprint filter in searchWithVec().
     */
    private function migrateVec(): void
    {
        if (!$this->vecAvailable()) {
            return;
        }

        $definition = $this->db->querySingle(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'fabby_vec'"
        );

        $recreated = false;

        if (
            is_string($definition)
            && preg_match('/embedding\s+float\[(\d+)\]/i', $definition, $matches) === 1
            && (int) $matches[1] !== $this->dims
        ) {
            $this->db->exec('DROP TABLE fabby_vec');
            $definition = false;
            $recreated = true;
        }

        if ($definition === false || $definition === null) {
            $this->db->exec(
                'CREATE VIRTUAL TABLE fabby_vec USING vec0(
                    chunk_id INTEGER PRIMARY KEY,
                    embedding float[' . $this->dims . ']
                )'
            );
            $recreated = true;
        }

        $projection = hash('sha256', $this->indexFingerprint . "\0" . $this->dims);

        if (!$recreated && $this->meta('vec_projection') === $projection) {
            return;
        }

        $this->db->exec('DELETE FROM fabby_vec');

        $select = $this->db->prepare(
            'SELECT id, embedding FROM fabby_chunks
             WHERE dims = :dims AND index_fingerprint = :fingerprint
             ORDER BY id'
        );
        $select->bindValue(':dims', $this->dims, SQLITE3_INTEGER);
        $select->bindValue(':fingerprint', $this->indexFingerprint, SQLITE3_TEXT);
        $rows = $select->execute();

        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $this->mirrorToVec(
                (int) $row['id'],
                self::unpack((string) $row['embedding'])
            );
        }

        $this->setMeta('vec_projection', $projection);
    }

    /**
     * Creates and, where needed, backfills the full-text index.
     *
     * The rebuild reads `title` and `chunk_text` and nothing else — embeddings
     * are never recomputed here, which matters because re-embedding costs API
     * calls. Measured at 1.8ms for 13 chunks and 26ms for 5000.
     *
     * External-content FTS tables report their content row count even when
     * the derived index is empty, so count(*) cannot detect a new table. Its
     * existence is captured before CREATE and a new table over existing chunks
     * is always rebuilt. FTS_SCHEMA_VERSION is deliberately independent from
     * the overall schema version: adding a queue column must not rebuild FTS.
     */
    private function migrateFts(int $fromVersion): void
    {
        if (!$this->ftsAvailable()) {
            return;
        }

        $existed = $this->db->querySingle(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'fabby_fts'"
        ) !== null;

        // External content: the index stores no copy of the text, it reads
        // fabby_chunks by rowid. remove_diacritics=2 aligns candidate lookup
        // with foldToken(); lexicalCoverage() still validates every hit.
        $this->db->exec(
            "CREATE VIRTUAL TABLE IF NOT EXISTS fabby_fts USING fts5(
                title,
                chunk_text,
                content = 'fabby_chunks',
                content_rowid = 'id',
                tokenize = 'unicode61 remove_diacritics 2'
            )"
        );

        $chunks = (int) $this->db->querySingle('SELECT count(*) FROM fabby_chunks');

        if ($chunks > 0 && (!$existed || $fromVersion < self::FTS_SCHEMA_VERSION)) {
            $this->db->exec("INSERT INTO fabby_fts(fabby_fts) VALUES('rebuild')");
        }
    }

    // --- vector helpers -----------------------------------------------------

    /**
     * Little-endian float32, explicitly. 'f' is machine-endian and would make
     * the database non-portable; 'g' is also exactly sqlite-vec's wire format.
     *
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return float[] */
    public static function unpack(string $blob): array
    {
        return array_values(unpack('g*', $blob) ?: []);
    }

    /**
     * @param float[] $vector
     * @return float[]
     */
    public static function normalize(array $vector): array
    {
        $sum = 0.0;

        foreach ($vector as $v) {
            $sum += $v * $v;
        }

        if ($sum <= 0.0) {
            return $vector;
        }

        $inv = 1.0 / sqrt($sum);

        return array_map(static fn (float $v): float => $v * $inv, $vector);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /** @return string[] */
    private static function meaningfulTokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text, 'UTF-8'), $matches);

        return array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => !in_array($token, self::STOP_WORDS, true)
        )));
    }

    /**
     * @param string[] $queryTokens
     */
    private static function lexicalCoverage(array $queryTokens, string $text): float
    {
        $textTokens = self::meaningfulTokens($text);

        if ($queryTokens === [] || $textTokens === []) {
            return 0.0;
        }

        $matches = 0;

        foreach ($queryTokens as $queryToken) {
            foreach ($textTokens as $textToken) {
                if (self::tokensMatch($queryToken, $textToken)) {
                    $matches++;
                    break;
                }
            }
        }

        return $matches / count($queryTokens);
    }

    /**
     * Treats only a small set of German suffixes as inflection. Diacritics are
     * folded first, but arbitrary shared prefixes and compounds do not match.
     */
    private static function tokensMatch(string $a, string $b): bool
    {
        $a = self::foldToken($a);
        $b = self::foldToken($b);

        if ($a === $b) {
            return true;
        }

        $aLength = mb_strlen($a, 'UTF-8');
        $bLength = mb_strlen($b, 'UTF-8');
        $shorter = $aLength < $bLength ? $a : $b;
        $longer = $aLength < $bLength ? $b : $a;

        if (mb_strlen($shorter, 'UTF-8') < self::INFLECTION_MIN_STEM_LENGTH
            || !str_starts_with($longer, $shorter)) {
            return false;
        }

        $suffix = mb_substr($longer, mb_strlen($shorter, 'UTF-8'), null, 'UTF-8');

        return in_array($suffix, self::INFLECTION_SUFFIXES, true);
    }

    private static function foldToken(string $token): string
    {
        return strtr(mb_strtolower($token, 'UTF-8'), [
            'ä' => 'a',
            'ö' => 'o',
            'ü' => 'u',
            'ß' => 'ss',
        ]);
    }

    private static function hitKey(SearchHit $hit): string
    {
        return $hit->pageKey . "\0" . $hit->chunkIndex;
    }
}
