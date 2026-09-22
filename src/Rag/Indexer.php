<?php

namespace Fabby\Rag;

use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Drives extraction, chunking, embedding and storage.
 *
 * Two properties matter most here, and both are about not spending money:
 *
 * - indexPage() short-circuits when the page's content hash is unchanged, so
 *   a Panel save that only toggled a checkbox costs zero API calls.
 * - rebuildAll() is resumable within a time budget. A rebuild that must finish
 *   in one request times out on any real site and leaves half an index.
 */
final class Indexer
{
    /** Guards against a runaway rebuild becoming a billing incident. */
    private const DEFAULT_PAGE_CAP = 500;

    public function __construct(
        private readonly App $kirby,
        private readonly VectorStore $store,
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly ContentExtractor $extractor,
        private readonly Chunker $chunker,
        private readonly IndexQueue $queue,
    ) {
    }

    /**
     * Indexes one page. Returns a result rather than throwing, so callers on
     * the request path cannot be broken by a content problem.
     */
    public function indexPage(Page $page, bool $force = false): IndexResult
    {
        $document = $this->extractor->extract($page);

        if ($document === null) {
            // Not indexable (or too short) — make sure any stale rows go.
            $this->store->deletePage(ContentExtractor::pageKey($page));

            return new IndexResult(pagesSkipped: 1);
        }

        if (!$force && $this->store->contentHashFor($document->pageKey) === $document->contentHash()) {
            $this->store->updatePageMetadata(
                pageKey: $document->pageKey,
                pageId: $document->pageId,
                url: $document->url,
                title: $document->title,
                modified: $document->modified,
            );

            return new IndexResult(pagesSkipped: 1);
        }

        $chunks = $this->chunker->chunk($document);

        if ($chunks === []) {
            $this->store->deletePage($document->pageKey);

            return new IndexResult(pagesSkipped: 1);
        }

        $vectors = $this->embeddings->embed($chunks);

        $this->store->replacePage(
            pageKey: $document->pageKey,
            pageId: $document->pageId,
            url: $document->url,
            title: $document->title,
            chunks: $chunks,
            vectors: $vectors,
            contentHash: $document->contentHash(),
            modified: $document->modified,
            model: $this->embeddings->model(),
        );

        return new IndexResult(
            pagesIndexed: 1,
            chunksWritten: count($chunks),
            embeddingCalls: 1,
        );
    }

    public function removePage(string $pageKey): IndexResult
    {
        $this->store->deletePage($pageKey);

        return new IndexResult(pagesRemoved: 1);
    }

    /**
     * Works through queued jobs.
     *
     * API failures leave jobs untouched for a later retry. A key cannot be a
     * local prerequisite here: many OpenAI-compatible self-hosted endpoints
     * deliberately run without authentication.
     */
    public function processQueue(int $maxJobs = 10, ?float $deadline = null): IndexResult
    {
        $lock = $this->queue->acquireWorkerLock();

        if ($lock === null) {
            return new IndexResult(
                pagesRemaining: $this->queue->pendingCount(),
                busy: true,
            );
        }

        try {
            return $this->processQueueLocked($maxJobs, $deadline);
        } finally {
            $this->queue->releaseWorkerLock($lock);
        }
    }

    private function processQueueLocked(int $maxJobs, ?float $deadline): IndexResult
    {
        $jobs = $this->queue->claim($maxJobs);

        if ($jobs === []) {
            return new IndexResult(pagesRemaining: $this->queue->pendingCount());
        }

        $result = new IndexResult();
        foreach ($jobs as $job) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            if ($job['operation'] === IndexQueue::OP_DELETE) {
                try {
                    $this->removePage($job['page_key']);
                    $this->queue->complete($job['page_key'], $job['revision']);
                    $result = $result->with(pagesRemoved: 1);
                } catch (\Throwable $e) {
                    // Preserve ordering: a following upsert from a slug/move
                    // must not run until deletion of the old key succeeded.
                    $this->queue->fail($job['page_key'], $job['revision'], $e->getMessage());
                    $result = $result->with(errors: [$job['page_id'] . ': ' . $e->getMessage()]);

                    break;
                }

                continue;
            }

            $page = $this->kirby->page($job['page_id']);

            if ($page === null) {
                // Page vanished between enqueue and drain.
                try {
                    $this->removePage($job['page_key']);
                    $this->queue->complete($job['page_key'], $job['revision']);
                    $result = $result->with(pagesRemoved: 1);
                } catch (\Throwable $e) {
                    $this->queue->fail($job['page_key'], $job['revision'], $e->getMessage());
                    $result = $result->with(errors: [$job['page_id'] . ': ' . $e->getMessage()]);
                }

                continue;
            }

            try {
                $one = $this->indexPage($page);
                $this->queue->complete($job['page_key'], $job['revision']);

                $result = $result->with(
                    pagesIndexed: $one->pagesIndexed,
                    pagesSkipped: $one->pagesSkipped,
                    chunksWritten: $one->chunksWritten,
                    embeddingCalls: $one->embeddingCalls,
                );
            } catch (\Throwable $e) {
                // Keep the job for a retry rather than losing the work.
                $this->queue->fail($job['page_key'], $job['revision'], $e->getMessage());
                $result = $result->with(errors: [$job['page_id'] . ': ' . $e->getMessage()]);
            }
        }

        $this->store->setMeta('last_drain_at', (string) time());

        return $result->with(pagesRemaining: $this->queue->pendingCount());
    }

    /**
     * Reconciles stored identities, queues current pages and drains what fits.
     *
     * The desired-key set is deliberately built before applying pageCap. A
     * page outside the billing guard is still valid and must never be mistaken
     * for an orphan. Stale deletes are queued first and IndexQueue claims all
     * deletes ahead of upserts, so cleanup works without an API key.
     */
    public function rebuildAll(
        ?float $deadline = null,
        int $pageCap = self::DEFAULT_PAGE_CAP,
        bool $force = false,
    ): IndexResult
    {
        $lock = $this->queue->acquireWorkerLock();

        if ($lock === null) {
            return new IndexResult(pagesRemaining: $this->queue->pendingCount(), busy: true);
        }

        try {
            if ($force) {
                $this->store->truncate();
                $this->queue->clear();
            }

            $this->enqueuePages($pageCap);

            return $this->processQueueLocked($this->queue->pendingCount(), $deadline);
        } finally {
            $this->queue->releaseWorkerLock($lock);
        }
    }

    /**
     * Persists the complete rebuild plan without embedding anything yet.
     *
     * The Panel uses this before it starts its sequence of short batch
     * requests. Unlike rebuildAll(), this deliberately has no 500-page
     * billing guard: collecting page identities is free and the durable queue
     * is the progress cursor for arbitrarily large sites.
     */
    public function enqueueAll(): int
    {
        $this->enqueuePages(null);

        return $this->queue->pendingCount();
    }

    /** Creates the durable Panel plan under the same lease as every worker. */
    public function prepareAll(bool $force = false): IndexResult
    {
        $lock = $this->queue->acquireWorkerLock();

        if ($lock === null) {
            return new IndexResult(pagesRemaining: $this->queue->pendingCount(), busy: true);
        }

        try {
            if ($force) {
                $this->store->truncate();
                $this->queue->clear();
            }

            $this->enqueuePages(null);

            return new IndexResult(pagesRemaining: $this->queue->pendingCount());
        } finally {
            $this->queue->releaseWorkerLock($lock);
        }
    }

    /** Pages that may be embedded. Drafts are excluded by index(false). */
    public function indexablePages(): array
    {
        $pages = [];

        foreach ($this->kirby->site()->index(false) as $page) {
            if ($this->extractor->isIndexable($page)) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    public function countIndexable(): int
    {
        return count($this->indexablePages());
    }

    /**
     * Pages that will actually end up in the index.
     *
     * Narrower than countIndexable(): isIndexable() only checks status and
     * template, while extract() additionally drops pages below the minimum
     * length. Without this distinction the Panel reports an empty stub page as
     * permanently "still missing".
     */
    public function countEmbeddable(): int
    {
        return count($this->embeddablePages());
    }

    public function countStaleIndexed(): int
    {
        return count($this->staleIndexedPages($this->embeddablePages()));
    }

    /** @return array<string, Page> keyed by the stable RAG identity */
    private function embeddablePages(): array
    {
        $pages = [];

        foreach ($this->indexablePages() as $page) {
            $document = $this->extractor->extract($page);

            if ($document !== null) {
                $pages[$document->pageKey] = $page;
            }
        }

        return $pages;
    }

    /**
     * @param array<string, Page> $embeddable
     * @return array<array{page_key: string, page_id: string}>
     */
    private function staleIndexedPages(array $embeddable): array
    {
        return array_values(array_filter(
            $this->store->indexedPages(),
            static fn (array $stored): bool => !isset($embeddable[$stored['page_key']])
        ));
    }

    /** Enqueues stale deletions first and then current pages. */
    private function enqueuePages(?int $pageCap): void
    {
        $embeddable = $this->embeddablePages();

        foreach ($this->staleIndexedPages($embeddable) as $stale) {
            $this->queue->enqueue(
                $stale['page_key'],
                $stale['page_id'],
                IndexQueue::OP_DELETE
            );
        }

        $queued = 0;

        foreach ($embeddable as $page) {
            if ($pageCap !== null && $queued >= $pageCap) {
                break;
            }

            $this->queue->enqueue(
                ContentExtractor::pageKey($page),
                $page->id(),
                IndexQueue::OP_UPSERT
            );
            $queued++;
        }

        $this->store->setMeta('last_full_rebuild_at', (string) time());
    }

}
