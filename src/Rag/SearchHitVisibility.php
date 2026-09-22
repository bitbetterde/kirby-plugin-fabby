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

        return $page !== null && $this->extractor->extract($page) !== null;
    }
}
