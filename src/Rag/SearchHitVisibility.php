<?php

namespace Fabby\Rag;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Uuid\Uuid;

/** Resolves a stored hit back to Kirby and applies the live indexing policy. */
final class SearchHitVisibility
{
    public function __construct(
        private readonly App $kirby,
        private readonly ContentExtractor $extractor,
        private readonly ?IndexQueue $queue = null,
    ) {
    }

    public function __invoke(SearchHit $hit): bool
    {
        $page = null;

        if (str_starts_with($hit->pageKey, 'page://')) {
            $model = Uuid::for($hit->pageKey)?->model();
            $page = $model instanceof Page ? $model : null;
        }

        $page ??= $this->kirby->page($hit->pageId);

        if ($page === null) {
            return false;
        }

        $document = $this->extractor->extract($page);

        if ($document === null) {
            return false;
        }

        // Related-page summaries are part of the extracted document. If a
        // target is withdrawn (or its text changes), the referring page can
        // remain public while its stored chunks still contain the old text.
        // Compare the hash carried by this hit, not a second database read
        // that might already observe a concurrent replacement of those rows.
        if ($hit->contentHash === '' || $hit->contentHash !== $document->contentHash()) {
            $this->queue?->enqueueIfMissing($document->pageKey, $document->pageId);

            return false;
        }

        return true;
    }
}
