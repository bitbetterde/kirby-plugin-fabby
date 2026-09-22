<?php

namespace Fabby\Rag;

/**
 * The indexable text of one Kirby page, already flattened and cleaned.
 *
 * Deliberately holds no Kirby types: everything downstream of extraction
 * (chunking, hashing, embedding, storage) works on this DTO alone, which is
 * what makes those stages testable without booting a Kirby App.
 */
final class ExtractedDocument
{
    public function __construct(
        /**
         * Stable identity for the page. The page UUID when the site has them,
         * otherwise the page id — $page->uuid() returns null when the site
         * config sets content.uuid to false, which is a supported setup.
         */
        public readonly string $pageKey,
        public readonly string $pageId,
        public readonly string $url,
        public readonly string $title,
        public readonly string $text,
        public readonly int $modified,
    ) {
    }

    /**
     * Identifies this revision of the page's content.
     *
     * Page-level rather than per-chunk on purpose: chunk boundaries shift as
     * soon as any text above them changes, so per-chunk hashes would almost
     * never hit. The indexer compares this against the stored value to skip
     * re-embedding a page that only had, say, a checkbox toggled.
     */
    public function contentHash(): string
    {
        return sha1($this->title . "\n" . $this->text);
    }
}
