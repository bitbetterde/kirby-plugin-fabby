<?php

namespace Fabby\Rag;

/**
 * What one indexing run did. Returned by every Indexer entry point and
 * rendered by the Panel dialogs and the CLI command.
 */
final class IndexResult
{
    /** @param string[] $errors */
    public function __construct(
        public readonly int $pagesIndexed = 0,
        public readonly int $pagesSkipped = 0,
        public readonly int $pagesRemoved = 0,
        public readonly int $chunksWritten = 0,
        public readonly int $embeddingCalls = 0,
        public readonly int $pagesRemaining = 0,
        public readonly array $errors = [],
        public readonly bool $busy = false,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** True when a time or size budget stopped the run before it finished. */
    public function isPartial(): bool
    {
        return $this->pagesRemaining > 0;
    }

    public function with(
        int $pagesIndexed = 0,
        int $pagesSkipped = 0,
        int $pagesRemoved = 0,
        int $chunksWritten = 0,
        int $embeddingCalls = 0,
        int $pagesRemaining = 0,
        array $errors = [],
        bool $busy = false,
    ): self {
        return new self(
            $this->pagesIndexed + $pagesIndexed,
            $this->pagesSkipped + $pagesSkipped,
            $this->pagesRemoved + $pagesRemoved,
            $this->chunksWritten + $chunksWritten,
            $this->embeddingCalls + $embeddingCalls,
            $pagesRemaining !== 0 ? $pagesRemaining : $this->pagesRemaining,
            array_merge($this->errors, $errors),
            $this->busy || $busy,
        );
    }
}
