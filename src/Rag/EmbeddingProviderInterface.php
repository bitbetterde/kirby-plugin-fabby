<?php

namespace Fabby\Rag;

/**
 * Turns text into vectors.
 *
 * Batch-first by design: OpenAI-compatible embeddings endpoints accept many
 * inputs per request, so a 40-chunk page is one HTTP round-trip rather than
 * forty. That is the single biggest lever on both indexing latency and
 * rate-limit exposure, so it belongs in the interface.
 */
interface EmbeddingProviderInterface
{
    /**
     * @param  string[]  $texts
     * @return float[][] One vector per input, in the same order.
     *
     * @throws EmbeddingException When the API cannot be reached or refuses.
     */
    public function embed(array $texts): array;

    /** Stored per row so a model switch makes stale vectors invisible. */
    public function model(): string;

    public function dimensions(): int;
}
