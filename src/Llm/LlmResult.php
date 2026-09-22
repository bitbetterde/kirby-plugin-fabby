<?php

namespace Fabby\Llm;

/**
 * Provider-agnostic result of a single chat-completion call.
 *
 * @property ToolCall[] $toolCalls
 */
final class LlmResult
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly mixed $rawResponse = null,
        public readonly ?string $error = null
    ) {
    }

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }
}
