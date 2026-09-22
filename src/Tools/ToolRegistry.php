<?php

namespace Fabby\Tools;

use Fabby\Config\FabbyConfig;
use Fabby\Llm\ToolDefinition;
use Fabby\Rag\RagFactory;
use Fabby\Rag\SearchHitVisibility;

/**
 * Bridges Panel-configured `tools` structure-field rows to the registered
 * ToolExecutorInterface implementations. Adding a new tool TYPE still
 * requires a new PHP class registered here; the structure field only
 * controls which registered tools are active and how they're parameterized
 * (e.g. the domain allowlist) — not arbitrary tool creation from the Panel.
 */
final class ToolRegistry
{
    /** @var array<string,callable(FabbyConfig):ToolExecutorInterface> */
    private const EXECUTOR_FACTORIES = [
        'web_search' => [self::class, 'makeWebSearchTool'],
        'knowledge_search' => [self::class, 'makeKnowledgeSearchTool'],
    ];

    /** @var array<string,array> name => definition array from *::definition() */
    private const DEFINITIONS = [
        'web_search' => [WebSearchTool::class, 'definition'],
        'knowledge_search' => [KnowledgeSearchTool::class, 'definition'],
    ];

    /**
     * Per-tool row normalisers.
     *
     * rowConfig() used to return ['domains' => ...] unconditionally and drop
     * every other field, so a second tool could never receive its own Panel
     * settings. Dispatching on the row's name keeps each tool's config local
     * to its normaliser.
     *
     * @var array<string,callable(array):array>
     */
    private const CONFIG_NORMALIZERS = [
        'web_search' => [self::class, 'normalizeWebSearchRow'],
        'knowledge_search' => [self::class, 'normalizeKnowledgeSearchRow'],
    ];

    public function __construct(private readonly FabbyConfig $config)
    {
    }

    /**
     * @return ToolDefinition[] Only rows marked enabled in the Panel.
     */
    public function enabledDefinitions(): array
    {
        $definitions = [];

        foreach ($this->enabledRows() as $name => $row) {
            if (!isset(self::DEFINITIONS[$name])) {
                continue;
            }

            $meta = call_user_func(self::DEFINITIONS[$name]);
            $definitions[] = new ToolDefinition(
                name: $meta['name'],
                description: $row['description'] ?: $meta['description'],
                parameterSchema: $meta['parameterSchema'],
                config: $this->rowConfig($row)
            );
        }

        return $definitions;
    }

    public function executorFor(string $name): ?ToolExecutorInterface
    {
        if (!isset(self::EXECUTOR_FACTORIES[$name])) {
            return null;
        }

        return call_user_func(self::EXECUTOR_FACTORIES[$name], $this->config);
    }

    public function configFor(string $name): array
    {
        $rows = $this->enabledRows();

        return isset($rows[$name]) ? $this->rowConfig($rows[$name]) : [];
    }

    /**
     * Whether the Panel has this tool switched on.
     *
     * executorFor() deliberately does not answer this — it builds whatever is
     * registered, so a caller cannot tell a disabled tool from an enabled one
     * by asking for its executor. Nor does an empty configFor(): a tool
     * without a normaliser legitimately has no config. A caller outside the
     * chat (the CLI) needs the row's state to avoid running with the tool's
     * own defaults while reporting Panel behaviour.
     */
    public function isEnabled(string $name): bool
    {
        return isset($this->enabledRows()[$name]);
    }

    /**
     * @return array<string,array> keyed by tool name, enabled rows only.
     */
    private function enabledRows(): array
    {
        $rows = [];

        foreach ($this->config->toolRows() as $row) {
            $name = $row['name'] ?? null;
            $enabled = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($name && $enabled) {
                $rows[$name] = $row;
            }
        }

        return $rows;
    }

    private function rowConfig(array $row): array
    {
        $name = (string) ($row['name'] ?? '');

        if (isset(self::CONFIG_NORMALIZERS[$name])) {
            return call_user_func(self::CONFIG_NORMALIZERS[$name], $row);
        }

        return [];
    }

    private static function normalizeWebSearchRow(array $row): array
    {
        $domains = [];

        if (!empty($row['domains'])) {
            $domains = array_values(array_filter(array_map('trim', explode("\n", (string) $row['domains']))));
        }

        return ['domains' => $domains];
    }

    /**
     * Clamped here rather than in the tool: an out-of-range Panel value must
     * never reach a SQL LIMIT or a similarity threshold.
     */
    private static function normalizeKnowledgeSearchRow(array $row): array
    {
        return [
            'top_k' => max(1, min(20, (int) ($row['rag_top_k'] ?? 5))),
            'min_similarity' => max(0.0, min(1.0, (float) ($row['rag_min_similarity'] ?? 0.25))),
            'max_chars' => max(500, min(12000, (int) ($row['rag_max_chars'] ?? 4000))),
        ];
    }

    private static function makeKnowledgeSearchTool(FabbyConfig $config): KnowledgeSearchTool
    {
        return new KnowledgeSearchTool(
            RagFactory::store($config),
            RagFactory::embeddings($config),
            new SearchHitVisibility($config->kirby(), RagFactory::extractor($config))
        );
    }

    private static function makeWebSearchTool(FabbyConfig $config): WebSearchTool
    {
        return new WebSearchTool(
            $config->secret('google_search_api_key'),
            $config->secret('google_search_engine_id')
        );
    }
}
