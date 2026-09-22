<?php

namespace Fabby\Llm;

/**
 * Provider-agnostic chat-completion abstraction. Implementations translate
 * ToolDefinition[]/ToolCall shapes into and out of their vendor's own wire
 * format (OpenAI function-calling, Anthropic tool_use, ...).
 *
 * The tool-execution loop itself (call -> run tools -> call again with
 * results) is NOT owned by the provider; it lives in Fabby\Chat\FabbyService
 * so that "one non-recursive follow-up call" stays an explicit, testable,
 * provider-independent policy instead of being duplicated per provider.
 */
interface LlmProviderInterface
{
    /**
     * @param ToolDefinition[] $tools Empty array if tools are disabled.
     * @param array $options e.g. ['model' => 'gpt-4o', 'maxTokens' => 1000]
     */
    public function chatCompletion(
        string $systemPrompt,
        string $userMessage,
        array $tools,
        array $options
    ): LlmResult;

    /**
     * Continue a conversation after executing the tool calls from a prior
     * chatCompletion() result, feeding the tool outputs back to the model
     * for a final answer. Tools are intentionally NOT re-offered here —
     * mirrors the current backend's non-recursive one-shot follow-up call.
     *
     * @param ToolCall[] $toolCalls The calls that were executed.
     * @param array<string,string> $toolResults Keyed by ToolCall::$id.
     */
    public function continueWithToolResults(
        string $systemPrompt,
        string $userMessage,
        array $toolCalls,
        array $toolResults,
        LlmResult $priorResult,
        array $options
    ): LlmResult;
}
