<?php

namespace Fabby\Rag;

/**
 * OpenAI-compatible /embeddings client.
 *
 * Mirrors OpenAiProvider deliberately: non-final, constructor-injected base
 * URL, and a protected call() — that is the seam the existing provider tests
 * subclass, and following it keeps the embedding tests on the same pattern.
 *
 * It does NOT extend OpenAiProvider. That class implements
 * LlmProviderInterface, whose methods are chat semantics; inheriting it would
 * force two meaningless implementations. Around thirty lines of duplicated
 * curl is the better trade against a wrong inheritance. The optional OpenAI
 * `dimensions` request field is intentionally omitted for broader server
 * compatibility; returned vectors are validated against the configured width.
 */
class OpenAiEmbeddings implements EmbeddingProviderInterface
{
    /**
     * Well below the API's per-request ceiling. Keeps payloads modest and
     * failures cheap to retry.
     */
    private const BATCH_SIZE = 96;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
        private readonly int $dimensions = 1536,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $vectors = [];

        foreach (array_chunk(array_values($texts), self::BATCH_SIZE) as $batch) {
            foreach ($this->embedBatch($batch) as $vector) {
                $vectors[] = $vector;
            }
        }

        if (count($vectors) !== count($texts)) {
            throw new EmbeddingException(
                'Die API lieferte ' . count($vectors) . ' Vektoren für ' . count($texts) . ' Texte.'
            );
        }

        return $vectors;
    }

    /**
     * @param  string[]  $batch
     * @return float[][]
     */
    private function embedBatch(array $batch): array
    {
        $payload = [
            'model' => $this->model,
            'input' => $batch,
        ];

        $response = $this->call('/embeddings', $payload);

        if ($response === null) {
            throw new EmbeddingException('Keine verwertbare Antwort der Embeddings-API.');
        }

        if (isset($response['error'])) {
            $error = $response['error'];
            $message = is_array($error) ? ($error['message'] ?? json_encode($error)) : $error;
            throw new EmbeddingException(is_scalar($message) ? (string) $message : 'Unbekannter Fehler der Embeddings-API.');
        }

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new EmbeddingException('Die Antwort der Embeddings-API enthält kein data-Feld.');
        }

        // The API returns an explicit index per item; order by it rather than
        // trusting the array order.
        $byIndex = [];

        foreach ($response['data'] as $position => $item) {
            $index = isset($item['index']) ? (int) $item['index'] : (int) $position;
            $embedding = $item['embedding'] ?? null;

            if (!is_array($embedding)) {
                throw new EmbeddingException('Ein Eintrag der Antwort enthält kein embedding-Array.');
            }

            $vector = array_map('floatval', $embedding);

            if (count($vector) !== $this->dimensions) {
                throw new EmbeddingException(sprintf(
                    'Embedding-Modell "%s" lieferte %d statt der konfigurierten %d Dimensionen.',
                    $this->model,
                    count($vector),
                    $this->dimensions
                ));
            }

            $byIndex[$index] = $vector;
        }

        ksort($byIndex);

        return array_values($byIndex);
    }

    /**
     * The network seam. Protected and overridable so tests can subclass and
     * capture the payload — the pattern OpenAiProviderToolMappingTest uses.
     */
    protected function call(string $path, array $data): ?array
    {
        $ch = curl_init($this->endpointUrl($path));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->requestHeaders());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Unlike the chat path, indexing runs unattended — a hung connection
        // must not pin a queue worker forever.
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        // Die alte explizite cURL-Freigabe ist unter PHP 8.5 deprecated.
        // Die Warnung wuerde von Kirbys Whoops-Handler in eine
        // Exception verwandelt und jede Indexierung scheitern lassen.
        unset($ch);

        if ($curlError !== '') {
            return ['error' => ['message' => 'Embeddings-API nicht erreichbar: ' . $curlError]];
        }

        $decoded = json_decode((string) $response, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            $message = is_array($error) ? ($error['message'] ?? null) : $error;

            return ['error' => ['message' => sprintf(
                'Embeddings-API HTTP %d%s',
                $statusCode,
                is_string($message) && $message !== '' ? ': ' . $message : ''
            )]];
        }

        return is_array($decoded)
            ? $decoded
            : ['error' => ['message' => 'Embeddings-API lieferte kein gültiges JSON.']];
    }

    protected function endpointUrl(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /** @return string[] */
    protected function requestHeaders(): array
    {
        $headers = ['Content-Type: application/json'];

        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        return $headers;
    }
}
