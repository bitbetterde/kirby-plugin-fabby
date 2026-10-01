<?php

namespace Fabby\Rag;

use SQLite3;

/**
 * Pending index work, so nothing slow ever runs on a Panel save.
 *
 * page_key is the PRIMARY KEY, which gives debouncing for free: ten rapid
 * saves of one page collapse into a single pending job. That is debouncing by
 * schema rather than by timing logic, and it cannot drift out of sync.
 *
 * Failures stay in the table with an attempt count and the error message, so a
 * failed embedding is durable and visible rather than silently lost. Jobs that
 * exhaust their attempts stop being claimed but remain readable for the Panel.
 */
final class IndexQueue
{
    public const OP_UPSERT = 'upsert';
    public const OP_DELETE = 'delete';

    /**
     * Not stored — resolved to upsert or delete when a page is enqueued,
     * depending on whether it may currently be indexed.
     */
    public const OP_AUTO = 'auto';

    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly VectorStore $store)
    {
    }

    /**
     * A delete supersedes a pending upsert for the same page: the page is
     * gone, so re-embedding it would fail anyway.
     */
    public function enqueue(string $pageKey, string $pageId, string $operation): void
    {
        $this->upsert($pageKey, $pageId, $operation, time());
    }

    /** Search can request a refresh without resetting retries or pending deletes. */
    public function enqueueIfMissing(string $pageKey, string $pageId): void
    {
        $stmt = $this->db()->prepare(
            "INSERT INTO fabby_queue
                (page_key, page_id, operation, enqueued_at, attempts, revision, last_error)
             VALUES (:key, :id, 'upsert', :now, 0, 1, NULL)
             ON CONFLICT(page_key) DO NOTHING"
        );
        $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
        $stmt->bindValue(':id', $pageId, SQLITE3_TEXT);
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * Atomically orders the old-key deletion before the new-key operation.
     * Explicit timestamps also preserve that order when either key already
     * had a debounced queue row with an older rowid.
     */
    public function enqueueTransition(
        string $oldPageKey,
        string $oldPageId,
        string $newPageKey,
        string $newPageId,
        string $newOperation,
    ): void {
        $db = $this->db();
        $db->exec('BEGIN IMMEDIATE');

        try {
            $now = time();
            $this->upsert($oldPageKey, $oldPageId, self::OP_DELETE, $now);
            $this->upsert($newPageKey, $newPageId, $newOperation, $now + 1);
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw $e;
        }
    }

    private function upsert(string $pageKey, string $pageId, string $operation, int $enqueuedAt): void
    {
        $stmt = $this->db()->prepare(
            'INSERT INTO fabby_queue
                (page_key, page_id, operation, enqueued_at, attempts, revision, last_error)
             VALUES (:key, :id, :op, :now, 0, 1, NULL)
             ON CONFLICT(page_key) DO UPDATE SET
                page_id = excluded.page_id,
                operation = excluded.operation,
                enqueued_at = excluded.enqueued_at,
                attempts = 0,
                revision = fabby_queue.revision + 1,
                last_error = NULL'
        );

        $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
        $stmt->bindValue(':id', $pageId, SQLITE3_TEXT);
        $stmt->bindValue(':op', $operation, SQLITE3_TEXT);
        $stmt->bindValue(':now', $enqueuedAt, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * @return array<array{page_key: string, page_id: string, operation: string, attempts: int, revision: int}>
     */
    public function claim(int $limit): array
    {
        $stmt = $this->db()->prepare(
            "SELECT page_key, page_id, operation, attempts, revision
             FROM fabby_queue
             WHERE attempts < :max
             ORDER BY CASE operation WHEN 'delete' THEN 0 ELSE 1 END,
                      enqueued_at ASC, rowid ASC
             LIMIT :limit"
        );

        $stmt->bindValue(':max', self::MAX_ATTEMPTS, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', max(0, $limit), SQLITE3_INTEGER);

        $result = $stmt->execute();
        $jobs = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $jobs[] = [
                'page_key' => (string) $row['page_key'],
                'page_id' => (string) $row['page_id'],
                'operation' => (string) $row['operation'],
                'attempts' => (int) $row['attempts'],
                'revision' => (int) $row['revision'],
            ];
        }

        return $jobs;
    }

    public function complete(string $pageKey, int $revision): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM fabby_queue WHERE page_key = :key AND revision = :revision'
        );
        $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
        $stmt->bindValue(':revision', $revision, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db()->changes() === 1;
    }

    /** Keeps the job so it can be retried, and records why it failed. */
    public function fail(string $pageKey, int $revision, string $error): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE fabby_queue
             SET attempts = attempts + 1, last_error = :err
             WHERE page_key = :key AND revision = :revision'
        );

        $stmt->bindValue(':err', mb_substr($error, 0, 500, 'UTF-8'), SQLITE3_TEXT);
        $stmt->bindValue(':key', $pageKey, SQLITE3_TEXT);
        $stmt->bindValue(':revision', $revision, SQLITE3_INTEGER);
        $stmt->execute();

        return $this->db()->changes() === 1;
    }

    /**
     * @return resource|null An exclusive process-wide worker lease.
     */
    public function acquireWorkerLock()
    {
        $lock = @fopen($this->store->workerLockPath(), 'c');

        if ($lock === false) {
            return null;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /** @param resource $lock */
    public function releaseWorkerLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function pendingCount(): int
    {
        return (int) $this->db()->querySingle(
            'SELECT COUNT(*) FROM fabby_queue WHERE attempts < ' . self::MAX_ATTEMPTS
        );
    }

    /** Jobs that gave up — surfaced in the Panel as a real problem. */
    public function failedCount(): int
    {
        return (int) $this->db()->querySingle(
            'SELECT COUNT(*) FROM fabby_queue WHERE attempts >= ' . self::MAX_ATTEMPTS
        );
    }

    /** @return array<array{page_id: string, last_error: string}> */
    public function failures(int $limit = 5): array
    {
        $stmt = $this->db()->prepare(
            'SELECT page_id, last_error FROM fabby_queue
             WHERE attempts >= :max ORDER BY enqueued_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':max', self::MAX_ATTEMPTS, SQLITE3_INTEGER);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $out = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = [
                'page_id' => (string) $row['page_id'],
                'last_error' => (string) ($row['last_error'] ?? ''),
            ];
        }

        return $out;
    }

    public function clear(): void
    {
        $this->db()->exec('DELETE FROM fabby_queue');
    }

    private function db(): SQLite3
    {
        return $this->store->connection();
    }
}
