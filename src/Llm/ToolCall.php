<?php

namespace Fabby\Llm;

/**
 * A provider-normalized tool invocation requested by the model.
 * `arguments` is always a decoded associative array, regardless of whether
 * the originating provider sent it as a JSON string (OpenAI) or a native
 * object (Anthropic) — callers never see the vendor's raw wire format.
 */
final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments
    ) {
    }
}
