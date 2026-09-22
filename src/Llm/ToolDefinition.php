<?php

namespace Fabby\Llm;

/**
 * A provider-agnostic tool specification. Parameters are expressed as JSON
 * Schema, the common subset both OpenAI (`parameters`) and Anthropic
 * (`input_schema`) accept — translating the schema into the vendor's exact
 * envelope key is each provider's own concern, not this value object's.
 */
final class ToolDefinition
{
    /**
     * @param array $parameterSchema JSON Schema object, e.g.
     *   ['type' => 'object', 'properties' => [...], 'required' => [...]]
     * @param array $config Tool-specific configuration read from the Panel,
     *   e.g. ['domains' => ['https://example.org/']] for web_search.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameterSchema,
        public readonly array $config = []
    ) {
    }
}
