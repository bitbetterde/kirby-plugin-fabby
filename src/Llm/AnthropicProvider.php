<?php

namespace Fabby\Llm;

/**
 * Placeholder for a future Anthropic implementation of LlmProviderInterface.
 * Not wired into LlmProviderFactory yet — selecting "anthropic" in the
 * Panel is not possible until this class is implemented and registered.
 *
 * When implemented, the translation concerns are:
 *   - ToolDefinition::$parameterSchema -> Anthropic `tools[].input_schema`
 *     (same JSON Schema, different key name than OpenAI's `parameters`).
 *   - Response tool use arrives as `content: [{type:"tool_use", id, name,
 *     input}]` where `input` is already a decoded object (unlike OpenAI's
 *     JSON-string `arguments`) — normalize into ToolCall the same way.
 *   - Follow-up messages use `role:"user", content:[{type:"tool_result",
 *     tool_use_id, content}]` instead of OpenAI's `role:"tool"` messages.
 */
final class AnthropicProvider implements LlmProviderInterface
{
    public function chatCompletion(
        string $systemPrompt,
        string $userMessage,
        array $tools,
        array $options
    ): LlmResult {
        throw new \RuntimeException('AnthropicProvider is not implemented yet.');
    }

    public function continueWithToolResults(
        string $systemPrompt,
        string $userMessage,
        array $toolCalls,
        array $toolResults,
        LlmResult $priorResult,
        array $options
    ): LlmResult {
        throw new \RuntimeException('AnthropicProvider is not implemented yet.');
    }
}
