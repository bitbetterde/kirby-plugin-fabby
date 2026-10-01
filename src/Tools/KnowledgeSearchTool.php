<?php

namespace Fabby\Tools;

use Fabby\Rag\EmbeddingProviderInterface;
use Fabby\Rag\SearchHit;
use Fabby\Rag\VectorStore;

/**
 * Semantic search over the site's own Kirby pages.
 *
 * The reason this exists next to web_search: a site that renders
 * client-side delivers HTML with almost no text, so an external search engine
 * can only index fragments. Running inside the CMS, this tool reads the
 * content tree directly and can cite the exact page.
 *
 * execute() never throws. FabbyService feeds the returned string straight back
 * to the model, so a failure has to read as a sentence the model can recover
 * from — an exception would turn the whole chat request into a 500.
 */
final class KnowledgeSearchTool implements ToolExecutorInterface
{
    private const DEFAULT_TOP_K = 5;
    private const DEFAULT_MIN_SIMILARITY = 0.25;
    private const DEFAULT_MAX_CHARS = 4000;

    /**
     * Per-hit excerpt cap.
     *
     * Sized to pass a whole chunk through, not to summarise it. A cap below
     * the chunk size silently cuts away the very passage that made the chunk
     * match — measured: a query for the workshop's machine list retrieved the
     * right chunk and then truncated the list out of the answer. The overall
     * max_chars budget, not this value, is what limits the total payload.
     */
    private const MAX_CHARS_PER_HIT = 1600;

    /** @var (\Closure(SearchHit): bool)|null */
    private readonly ?\Closure $isHitVisible;

    public function __construct(
        private readonly VectorStore $store,
        private readonly EmbeddingProviderInterface $embeddings,
        ?callable $isHitVisible = null,
    ) {
        $this->isHitVisible = $isHitVisible === null
            ? null
            : \Closure::fromCallable($isHitVisible);
    }

    public static function definition(): array
    {
        return [
            'name' => 'knowledge_search',
            'description' => 'Durchsucht die Inhalte der Website der FAB Region '
                . '(Projekte, Angebote, Werkstätten, Beratung, Veranstaltungen, Orte, '
                . 'Ansprechpartner, Kreislaufwirtschaft-Themen) direkt im CMS.'
                . "\n\n"
                . 'IMMER zuerst aufrufen, wenn die Frage ein Thema berührt, zu dem die '
                . 'FAB Region ein Angebot haben könnte — auch wenn die Frage allgemein '
                . 'formuliert ist und die Region nicht ausdrücklich nennt. Beispiele: '
                . 'reparieren, gründen, beraten lassen, mitmachen, leihen, teilen, '
                . 'Werkstatt, Kurse, Termine, Öffnungszeiten, Ansprechpartner. '
                . 'Eine allgemein gestellte Frage wie "Wie gründe ich ein Unternehmen?" '
                . 'ist genau so ein Fall: Zuerst suchen, dann antworten.'
                . "\n\n"
                . 'Nur überspringen, wenn die Frage sicher nichts mit der Region oder '
                . 'ihren Themen zu tun hat (z. B. reines Allgemeinwissen). '
                . 'Jedes Ergebnis enthält eine Zeile "URL:". Gib diese URL in der '
                . 'Antwort an, damit Nutzerinnen und Nutzer weiterlesen können — '
                . 'übernimm sie ZEICHENGENAU so, wie sie im Ergebnis steht. '
                . 'Erfinde niemals eine Domain, einen Pfad oder eine Seite, die nicht '
                . 'in den Ergebnissen vorkommt.',
            'parameterSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Die Suchanfrage in natürlicher Sprache, auf Deutsch.',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function execute(array $arguments, array $config): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return 'Fehler: Es wurde keine Suchanfrage übergeben.';
        }

        $topK = (int) ($config['top_k'] ?? self::DEFAULT_TOP_K);
        $minSimilarity = (float) ($config['min_similarity'] ?? self::DEFAULT_MIN_SIMILARITY);
        $maxChars = (int) ($config['max_chars'] ?? self::DEFAULT_MAX_CHARS);

        try {
            $vectors = $this->embeddings->embed([$query]);

            if ($vectors === []) {
                return 'Die Wissensdatenbank ist derzeit nicht verfügbar.';
            }

            // Fetch beyond top_k so withdrawn hits can be removed without
            // displacing valid results that ranked immediately behind them.
            // The surplus is VectorStore's rule, not a second copy of it here.
            $hits = $this->store->hybridSearch(
                $vectors[0],
                $query,
                $this->embeddings->model(),
                $topK,
                $minSimilarity,
                VectorStore::candidatePoolSize($topK)
            );
            $hits = array_slice($this->visibleHits($hits), 0, $topK);
        } catch (\Throwable $e) {
            // Deliberately a readable German sentence: the model sees this and
            // can tell the user something useful instead of the chat failing.
            return 'Die Wissensdatenbank konnte nicht durchsucht werden ('
                . $e->getMessage() . '). Bitte antworte aus deinem allgemeinen Wissen '
                . 'oder nutze ein anderes Tool.';
        }

        if ($hits === []) {
            return 'Keine passenden Inhalte auf der Website gefunden. '
                . 'Formuliere die Suche gegebenenfalls anders oder antworte aus '
                . 'deinem allgemeinen Wissen.';
        }

        return $this->format($hits, $maxChars);
    }

    /**
     * @param SearchHit[] $hits
     * @return SearchHit[]
     */
    private function visibleHits(array $hits): array
    {
        if ($this->isHitVisible === null) {
            return $hits;
        }

        return array_values(array_filter($hits, function (SearchHit $hit): bool {
            try {
                return ($this->isHitVisible)($hit) === true;
            } catch (\Throwable) {
                // If current Kirby state cannot be established, never expose
                // potentially withdrawn content from the stale local index.
                return false;
            }
        }));
    }

    /**
     * @param SearchHit[] $hits
     */
    private function format(array $hits, int $maxChars): string
    {
        $out = '';

        foreach ($hits as $i => $hit) {
            $entry = sprintf(
                "[%d] %s\nURL: %s\nSemantische Ähnlichkeit: %.2f\n%s\n\n",
                $i + 1,
                $hit->title,
                $hit->url,
                $hit->similarity,
                $this->excerpt($hit->text)
            );

            // Stop before exceeding the budget rather than truncating an entry
            // mid-URL, which would give the model an unusable citation.
            if ($out !== '' && mb_strlen($out . $entry, 'UTF-8') > $maxChars) {
                break;
            }

            $out .= $entry;
        }

        return rtrim($out);
    }

    private function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text, 'UTF-8') <= self::MAX_CHARS_PER_HIT) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CHARS_PER_HIT, 'UTF-8') . '…';
    }
}
