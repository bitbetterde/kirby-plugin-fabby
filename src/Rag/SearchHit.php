<?php

namespace Fabby\Rag;

/**
 * One retrieved chunk, with the page data needed to cite it.
 */
final class SearchHit
{
    public function __construct(
        public readonly string $pageId,
        public readonly string $url,
        public readonly string $title,
        public readonly string $text,
        public readonly int $chunkIndex,
        /** Cosine similarity in [-1, 1]; in practice [0, 1] for text embeddings. */
        public readonly float $similarity,
        /** Stable Kirby UUID URI, falling back to the page id when UUIDs are disabled. */
        public readonly string $pageKey = '',
        /** Hash of the extracted document from which this chunk was stored. */
        public readonly string $contentHash = '',
    ) {
    }
}
