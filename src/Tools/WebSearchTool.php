<?php

namespace Fabby\Tools;

/**
 * Google Custom Search implementation, ported from the original
 * fabby.php::performWebSearch(). Unlike the original, the allowed-domain
 * list is read ONLY from Panel config (`$config['domains']`) — this is the
 * single source that replaces the three inconsistent lists that used to
 * exist (the 7-URL GPT_WEB_SEARCH_DOMAINS array, the same 7 URLs repeated
 * as plain text inside the system prompt, and a separate hardcoded 5-site
 * list inside performWebSearch itself).
 */
final class WebSearchTool implements ToolExecutorInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $searchEngineId
    ) {
    }

    public static function definition(): array
    {
        return [
            'name' => 'web_search',
            'description' => 'Search for information about FAB Bergisch events, projects, news, and circular economy topics. Always use this for questions about events or current information.',
            'parameterSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query.',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    public function execute(array $arguments, array $config): string
    {
        $query = $arguments['query'] ?? '';

        if ($query === '' || $this->apiKey === '' || $this->searchEngineId === '') {
            return 'Error: Search API keys not configured.';
        }

        $domains = $config['domains'] ?? [];
        $cleanQuery = preg_replace('/\s*site:[^\s]+/', '', $query);
        $enhancedQuery = $cleanQuery;

        if (count($domains) > 0) {
            $siteRestrictions = array_map(
                fn (string $domain) => 'site:' . preg_replace('#^https?://#', '', rtrim($domain, '/')),
                $domains
            );
            $enhancedQuery .= ' (' . implode(' OR ', $siteRestrictions) . ')';
        }

        $url = 'https://www.googleapis.com/customsearch/v1?key=' . urlencode($this->apiKey)
            . '&cx=' . urlencode($this->searchEngineId)
            . '&q=' . urlencode($enhancedQuery);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $result = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($curlError !== '') {
            return 'No results found. Error: ' . $curlError;
        }

        if ($statusCode !== 200) {
            return 'No results found. HTTP status: ' . $statusCode;
        }

        $data = json_decode((string) $result, true);

        if (!isset($data['items'])) {
            if (isset($data['error'])) {
                return 'No results found. Error: ' . json_encode($data['error']);
            }
            return 'No results found.';
        }

        $snippets = '';
        foreach ($data['items'] as $item) {
            $snippets .= "Title: {$item['title']}\n";
            $snippets .= "Link: {$item['link']}\n";
            $snippets .= "Snippet: {$item['snippet']}\n\n";
        }

        return $snippets;
    }
}
