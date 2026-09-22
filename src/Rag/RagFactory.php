<?php

namespace Fabby\Rag;

use Fabby\Config\FabbyConfig;
use Kirby\Cms\App;

/**
 * Assembles the RAG components from Panel configuration.
 *
 * One place does the wiring, because four callers need it — the hooks in
 * index.php, the Panel dialogs, the CLI command and ToolRegistry — and four
 * copies would drift. ApiController::makeService() is the existing precedent
 * for this static-factory style.
 *
 * The store is memoised per database path: ToolRegistry builds a fresh
 * executor for every tool call, and opening a second SQLite connection (plus
 * re-running migrations and the extension probe) for a second call in the same
 * request would be pure waste.
 */
final class RagFactory
{
    /** @var array<string, VectorStore> */
    private static array $stores = [];

    public static function store(FabbyConfig $config): VectorStore
    {
        $path = $config->ragDbPath();
        $fingerprint = $config->ragIndexFingerprint();
        $key = hash('sha256', implode("\0", [
            $path,
            $config->ragSqliteVecFile(),
            (string) $config->ragEmbeddingDimensions(),
            $fingerprint,
        ]));

        if (!isset(self::$stores[$key])) {
            $store = new VectorStore(
                $path,
                $config->ragSqliteVecFile(),
                $config->ragEmbeddingDimensions(),
                $fingerprint,
            );
            $store->connect();

            // Record how the search is running, so the Panel can show it
            // without opening its own connection.
            $diagnostics = $store->vecDiagnostics();
            $store->setMeta('vec_status', (string) $diagnostics['status']);
            $store->setMeta('vec_reason', (string) $diagnostics['reason']);

            $fts = $store->ftsDiagnostics();
            $store->setMeta('fts_status', (string) $fts['status']);
            $store->setMeta('fts_reason', (string) $fts['reason']);

            self::$stores[$key] = $store;
        }

        return self::$stores[$key];
    }

    public static function embeddings(FabbyConfig $config): EmbeddingProviderInterface
    {
        return new OpenAiEmbeddings(
            $config->ragEmbeddingApiKey(),
            $config->ragEmbeddingModel(),
            $config->ragEmbeddingDimensions(),
            $config->ragEmbeddingBaseUrl()
        );
    }

    public static function extractor(FabbyConfig $config): ContentExtractor
    {
        return new ContentExtractor(80, $config->ragBaseUrl(), $config->ragListedOnly());
    }

    public static function queue(FabbyConfig $config): IndexQueue
    {
        return new IndexQueue(self::store($config));
    }

    public static function lifecycle(FabbyConfig $config): PageLifecycleHandler
    {
        return new PageLifecycleHandler(
            self::store($config),
            self::extractor($config),
            self::queue($config)
        );
    }

    public static function indexer(App $kirby, FabbyConfig $config): Indexer
    {
        return new Indexer(
            $kirby,
            self::store($config),
            self::embeddings($config),
            self::extractor($config),
            new Chunker($config->ragChunkChars(), $config->ragChunkOverlap()),
            self::queue($config)
        );
    }

    /** Test seam: drops memoised connections. */
    public static function reset(): void
    {
        foreach (self::$stores as $store) {
            $store->close();
        }

        self::$stores = [];
    }
}
