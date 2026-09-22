<?php

namespace Fabby\Llm;

use Fabby\Config\FabbyConfig;

/**
 * Resolves the Panel-configured provider name to an LlmProviderInterface
 * instance. The historic 'openai' registry name now represents the
 * OpenAI-compatible Chat Completions protocol; the configured base URL picks
 * OpenAI itself, a third-party service or a self-hosted server.
 */
final class LlmProviderFactory
{
    /** @var array<string,string> provider name => FQCN */
    private const REGISTRY = [
        'openai' => OpenAiProvider::class,
    ];

    public function __construct(private readonly FabbyConfig $config)
    {
    }

    /**
     * @return string[] Provider names available for selection, e.g. in the
     *   Panel blueprint's `llm_provider` select field options.
     */
    public static function available(): array
    {
        return array_keys(self::REGISTRY);
    }

    public function make(string $providerName): LlmProviderInterface
    {
        if (!isset(self::REGISTRY[$providerName])) {
            throw new \InvalidArgumentException(
                "Unknown or not-yet-implemented LLM provider '{$providerName}'. " .
                'Available: ' . implode(', ', self::available())
            );
        }

        return match ($providerName) {
            'openai' => new OpenAiProvider(
                $this->config->llmApiKey(),
                $this->config->llmBaseUrl()
            ),
            default => throw new \InvalidArgumentException("Unhandled provider '{$providerName}'"),
        };
    }
}
