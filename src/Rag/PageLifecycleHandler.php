<?php

namespace Fabby\Rag;

use Kirby\Cms\Page;

/**
 * Keeps local RAG state aligned with Kirby page lifecycle events.
 *
 * Every reaction only writes to the local queue; the worker does the expensive
 * work. A Panel save therefore performs no embedding request, and all callers
 * share the same worker path.
 *
 * Withdrawn content stays in SQLite until the queue is drained, which is safe
 * because SearchHitVisibility resolves every hit back to Kirby at query time
 * and drops it once the page is gone or no longer indexable. Deletion is about
 * reclaiming storage, not about hiding content.
 */
final class PageLifecycleHandler
{
    private const ERROR_META = 'last_lifecycle_error';
    private const ERROR_AT_META = 'last_lifecycle_error_at';

    public function __construct(
        private readonly VectorStore $store,
        private readonly ContentExtractor $extractor,
        private readonly IndexQueue $queue,
    ) {
    }

    public function created(Page $page): void
    {
        $this->syncCurrent($page);
    }

    public function deleted(Page $page): void
    {
        $this->queueDelete(ContentExtractor::pageKey($page), $page->id());
    }

    public function changed(Page $newPage, Page $oldPage): void
    {
        $newKey = ContentExtractor::pageKey($newPage);
        $oldKey = $this->oldPageKey($oldPage, $newKey);
        $document = $this->document($newPage);

        if ($document === null) {
            $this->queueDelete($oldKey, $oldPage->id());

            if ($newKey !== $oldKey) {
                $this->queueDelete($newKey, $newPage->id());
            }

            return;
        }

        if ($oldKey !== $newKey) {
            // One transaction, so the old-key deletion can never be claimed
            // after the upsert that replaces it.
            try {
                $this->queue->enqueueTransition(
                    $oldKey,
                    $oldPage->id(),
                    $newKey,
                    $newPage->id(),
                    IndexQueue::OP_UPSERT
                );
                $this->clearError();
            } catch (\Throwable $e) {
                $this->recordError(
                    $newPage->id() . ': Umbenennung konnte nicht vorgemerkt werden (' . $e->getMessage() . ')'
                );

                throw $e;
            }

            return;
        }

        $this->queue->enqueue($newKey, $newPage->id(), IndexQueue::OP_UPSERT);
        $this->clearError();
    }

    /**
     * Kirby's after-hook receives an old model whose directory has already
     * moved. Reading its content to discover the UUID can therefore throw the
     * stale-page protection from PlainTextStorage. Prefer the real old UUID
     * where it is still readable, but a UUID from the fresh model is by
     * definition the same stable identity and is the safe fallback.
     */
    private function oldPageKey(Page $oldPage, string $newKey): string
    {
        try {
            return ContentExtractor::pageKey($oldPage);
        } catch (\Throwable $e) {
            if (str_starts_with($newKey, 'page://')) {
                return $newKey;
            }

            // UUIDs are disabled: the old path is the intended fallback key
            // and does not require reading the now-moved content file.
            return 'page-id://' . $oldPage->id();
        }
    }

    private function syncCurrent(Page $page): void
    {
        $key = ContentExtractor::pageKey($page);

        if ($this->document($page) === null) {
            $this->queueDelete($key, $page->id());

            return;
        }

        $this->queue->enqueue($key, $page->id(), IndexQueue::OP_UPSERT);
        $this->clearError();
    }

    /**
     * Returns null for every state that must not remain searchable. If rich
     * content extraction itself fails, keep a retryable upsert but report the
     * problem; the runtime visibility check will fail closed in the meantime.
     */
    private function document(Page $page): ?ExtractedDocument
    {
        if (!$this->extractor->isIndexable($page)) {
            return null;
        }

        try {
            return $this->extractor->extract($page);
        } catch (\Throwable $e) {
            $this->queue->enqueue(
                ContentExtractor::pageKey($page),
                $page->id(),
                IndexQueue::OP_UPSERT
            );
            $this->recordError($page->id() . ': Inhalt konnte nicht geprüft werden (' . $e->getMessage() . ')');

            throw $e;
        }
    }

    /**
     * A queued delete replaces a pending upsert for the same page, because
     * page_key is the queue's primary key. Cancelling the obsolete embedding
     * therefore needs no separate step.
     */
    private function queueDelete(string $pageKey, string $pageId): void
    {
        try {
            $this->queue->enqueue($pageKey, $pageId, IndexQueue::OP_DELETE);
            $this->clearError();
        } catch (\Throwable $e) {
            $this->recordError(
                $pageId . ': Löschung konnte nicht vorgemerkt werden (' . $e->getMessage() . ')'
            );

            throw $e;
        }
    }

    private function clearError(): void
    {
        try {
            $this->store->setMeta(self::ERROR_META, '');
            $this->store->setMeta(self::ERROR_AT_META, '');
        } catch (\Throwable) {
            // If SQLite itself is unavailable, the outer operation already
            // reports the actionable error. Status metadata is best-effort.
        }
    }

    private function recordError(string $message): void
    {
        $message = mb_substr($message, 0, 500, 'UTF-8');
        error_log('[fabby-rag] ' . $message);

        try {
            $this->store->setMeta(self::ERROR_META, $message);
            $this->store->setMeta(self::ERROR_AT_META, (string) time());
        } catch (\Throwable) {
            // The PHP error log remains available when SQLite cannot record
            // its own failure.
        }
    }
}
