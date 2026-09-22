<?php

namespace Fabby\Llm;

/**
 * OpenAI-compatible Chat Completions implementation of LlmProviderInterface.
 * Ported from the original Backend/Github/FTP_test/api/etc/fabby.php
 * (callGPT + question_to_answer), split so the OpenAI-specific
 * tool-calling translation lives entirely in this class. The configurable
 * base URL also covers compatible self-hosted and third-party APIs.
 */
class OpenAiProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1'
    ) {
    }

    public function chatCompletion(
        string $systemPrompt,
        string $userMessage,
        array $tools,
        array $options
    ): LlmResult {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $payload = $this->buildPayload($messages, $tools, $options);
        $response = $this->call('/chat/completions', $payload);

        return $this->toResult($response);
    }

    public function continueWithToolResults(
        string $systemPrompt,
        string $userMessage,
        array $toolCalls,
        array $toolResults,
        LlmResult $priorResult,
        array $options
    ): LlmResult {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
            $this->assistantToolCallMessage($toolCalls),
        ];

        foreach ($toolCalls as $call) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $call->id,
                'content' => $toolResults[$call->id] ?? '',
            ];
        }

        // Deliberately no `tools` key: mirrors the original single
        // non-recursive follow-up call, tools are not re-offered.
        $payload = $this->buildPayload($messages, [], $options, includeTools: false);
        $response = $this->call('/chat/completions', $payload);

        return $this->toResult($response);
    }

    /**
     * @param ToolDefinition[] $tools
     */
    protected function buildPayload(array $messages, array $tools, array $options, bool $includeTools = true): array
    {
        $payload = [
            'model' => $options['model'] ?? 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => $options['maxTokens'] ?? 1000,
        ];

        if ($includeTools && count($tools) > 0) {
            $payload['tools'] = array_map(
                fn (ToolDefinition $tool) => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'parameters' => $tool->parameterSchema,
                    ],
                ],
                $tools
            );
        }

        return $payload;
    }

    /**
     * Rebuilds the `assistant` message with tool_calls exactly as OpenAI's
     * API requires it to precede the `tool` role messages in the follow-up
     * request. Reconstructed from our normalized ToolCall[] rather than
     * re-using the raw API response, so this class is the only place that
     * needs to know OpenAI's exact message shape.
     *
     * @param ToolCall[] $toolCalls
     */
    private function assistantToolCallMessage(array $toolCalls): array
    {
        return [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => array_map(
                fn (ToolCall $call) => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => json_encode($call->arguments, JSON_UNESCAPED_UNICODE),
                    ],
                ],
                $toolCalls
            ),
        ];
    }

    protected function toResult(?array $response): LlmResult
    {
        if ($response === null) {
            return new LlmResult(content: null, error: 'Keine verwertbare Antwort der Completion-API.');
        }

        if (array_key_exists('error', $response)) {
            $error = $response['error'];
            $message = is_array($error) ? ($error['message'] ?? null) : $error;

            return new LlmResult(
                content: null,
                rawResponse: $response,
                error: is_scalar($message) ? (string) $message : 'Unbekannter Fehler der Completion-API.'
            );
        }

        $message = $response['choices'][0]['message'] ?? null;

        if ($message === null) {
            return new LlmResult(content: null, rawResponse: $response, error: 'No content in response');
        }

        if (!empty($message['tool_calls'])) {
            $toolCalls = array_map(
                function (array $raw): ToolCall {
                    $arguments = json_decode($raw['function']['arguments'] ?? '{}', true);
                    return new ToolCall(
                        id: $raw['id'],
                        name: $raw['function']['name'],
                        arguments: is_array($arguments) ? $arguments : []
                    );
                },
                $message['tool_calls']
            );

            return new LlmResult(content: null, toolCalls: $toolCalls, rawResponse: $response);
        }

        $content = $message['content'] ?? null;

        if ($content === null) {
            return new LlmResult(content: null, rawResponse: $response, error: 'No content in response');
        }

        return new LlmResult(content: $content, rawResponse: $response);
    }

    protected function call(string $path, array $data): ?array
    {
        $ch = curl_init($this->endpointUrl($path));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->requestHeaders());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);

        if ($curlError !== '') {
            return ['error' => ['message' => 'Completion-API nicht erreichbar: ' . $curlError]];
        }

        $decoded = json_decode((string) $response, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            $message = is_array($error) ? ($error['message'] ?? null) : $error;

            return ['error' => ['message' => sprintf(
                'Completion-API HTTP %d%s',
                $statusCode,
                is_string($message) && $message !== '' ? ': ' . $message : ''
            )]];
        }

        return is_array($decoded)
            ? $decoded
            : ['error' => ['message' => 'Completion-API lieferte kein gültiges JSON.']];
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
