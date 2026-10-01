<?php

/** Run with: php tests/lifecycle.php (or set FABBY_KIRBY_ROOT). */
$kirbyRoot = getenv('FABBY_KIRBY_ROOT') ?: dirname(__DIR__, 4);
require $kirbyRoot . '/kirby/bootstrap.php';
require dirname(__DIR__) . '/index.php';

use Fabby\Config\FabbyConfig;
use Fabby\Rag\Chunker;
use Fabby\Rag\ContentExtractor;
use Fabby\Rag\EmbeddingProviderInterface;
use Fabby\Rag\Indexer;
use Fabby\Rag\IndexQueue;
use Fabby\Rag\RagFactory;
use Kirby\Cms\App;
use Kirby\Data\Data;
use Kirby\Filesystem\Dir;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$root = sys_get_temp_dir() . '/fabby-lifecycle-' . bin2hex(random_bytes(8));
try {
    foreach ([true, false] as $uuids) {
        foreach (['rename', 'move', 'rename-then-move'] as $action) {
            foreach (['public', 'short', 'excluded'] as $parentState) {
                $fixture = $root . '/' . (int) $uuids . '-' . $action . '-' . $parentState;
                $writePage = static function (string $path, array $content) use ($fixture): void {
                    mkdir($fixture . '/content/' . $path, 0700, true);
                    file_put_contents($fixture . '/content/' . $path . '/default.txt', Data::encode($content, 'txt'));
                };
                $text = str_repeat('Public workshop description for the lifecycle regression. ', 5);
                $writePage('fabby-settings', [
                    'title' => 'Fabby', 'rag_enabled' => 'true',
                    'rag_embedding_dimensions' => '2', 'rag_embedding_model' => 'test',
                    'rag_db_path' => $fixture . '/index.sqlite',
                ]);
                $writePage('parent', [
                    'title' => 'Parent', 'text' => $parentState === 'short' ? '' : $text,
                    'fabby_exclude' => $parentState === 'excluded' ? 'true' : 'false',
                ]);
                $writePage('parent/child', ['title' => 'Child', 'text' => $text]);
                $writePage('parent/child/grandchild', ['title' => 'Grandchild', 'text' => $text]);
                $writePage('parent/pending', ['title' => 'Pending', 'text' => $text]);
                $writePage('parent/excluded', ['title' => 'Excluded', 'text' => $text, 'fabby_exclude' => 'true']);
                $writePage('parent/_drafts/draft', ['title' => 'Draft', 'text' => $text]);
                $writePage('parent-copy', ['title' => 'Unrelated', 'text' => $text]);
                $writePage('destination', ['title' => 'Destination']);
                mkdir($fixture . '/site/blueprints/pages', 0700, true);
                file_put_contents($fixture . '/site/blueprints/pages/default.yml', "title: Default\nsections:\n  content:\n    type: fields\n    fields:\n      text:\n        type: textarea\n  children:\n    type: pages\n    templates:\n      - default\n");

                $app = new App([
                    'roots' => ['index' => $fixture, 'kirby' => $kirbyRoot . '/kirby'],
                    'urls' => ['index' => 'https://example.test'],
                    'options' => ['content.uuid' => $uuids],
                    'user' => 'kirby',
                ]);
                $config = new FabbyConfig($app);
                $store = RagFactory::store($config);
                $extractor = RagFactory::extractor($config);
                $queue = RagFactory::queue($config);
                $embeddings = new class implements EmbeddingProviderInterface {
                    public int $calls = 0;
                    public function embed(array $texts): array
                    {
                        $this->calls++;
                        return array_fill(0, count($texts), [1.0, 0.0]);
                    }
                    public function model(): string { return 'test'; }
                    public function dimensions(): int { return 2; }
                };
                $indexer = new Indexer($app, $store, $embeddings, $extractor, new Chunker(), $queue);
                $parent = $app->page('parent');
                foreach (['parent', 'parent/child', 'parent/child/grandchild', 'parent-copy'] as $id) {
                    $indexer->indexPage($app->page($id));
                }
                $oldKeys = [];
                foreach ($parent->index(true) as $page) {
                    $oldKeys[$page->id()] = ContentExtractor::pageKey($page);
                    if (!$extractor->isIndexable($page)) {
                        // Stale chunks must be removed even for descendants
                        // that have since become drafts or been excluded.
                        $store->replacePage(
                            pageKey: $oldKeys[$page->id()], pageId: $page->id(),
                            url: $page->url(), title: (string) $page->title(),
                            chunks: [$text], vectors: [[1.0, 0.0]],
                            contentHash: 'stale', modified: 0, model: 'test',
                        );
                    }
                    // Simulate pending work, including obsolete jobs for
                    // withdrawn pages and a not-yet-indexed public page.
                    $queue->enqueue($oldKeys[$page->id()], $page->id(), IndexQueue::OP_UPSERT);
                }
                $callsBefore = $embeddings->calls;

                // Exercise real filesystem changes and the registered hooks,
                // not a manually constructed pair of old/new page models.
                if ($action !== 'move') {
                    $parent = $parent->changeSlug('renamed');
                }
                if ($action !== 'rename') {
                    $parent = $parent->move($app->page('destination'));
                }
                check($embeddings->calls === $callsBefore, 'Lifecycle hook made an embedding request');
                $newId = $parent->id();
                $jobs = array_column($queue->claim(100), null, 'page_key');
                foreach ($oldKeys as $oldId => $oldKey) {
                    $id = $newId . substr($oldId, strlen('parent'));
                    $page = $app->page($id);
                    check($page !== null, 'Moved descendant cannot be resolved: ' . $id);
                    $key = ContentExtractor::pageKey($page);
                    $operation = $extractor->isIndexable($page) ? IndexQueue::OP_UPSERT : IndexQueue::OP_DELETE;
                    check(($jobs[$key]['operation'] ?? null) === $operation, 'Wrong descendant operation: ' . $id);
                    if ($operation === IndexQueue::OP_UPSERT) {
                        check($jobs[$key]['page_id'] === $id, 'Queue retained old descendant id: ' . $oldId);
                    }
                    if (!$uuids) {
                        check(($jobs[$oldKey]['operation'] ?? null) === IndexQueue::OP_DELETE, 'Old path job was not deleted: ' . $oldId);
                    }
                }
                check(!isset($jobs[ContentExtractor::pageKey($app->page('parent-copy'))]), 'Unrelated sibling was queued');
                $result = $indexer->processQueue(100);
                check($result->errors === [], 'Queue processing failed: ' . implode('; ', $result->errors));
                check($queue->pendingCount() === 0, 'Subtree jobs were not drained');
                $expected = [$newId . '/child', $newId . '/child/grandchild', $newId . '/pending', 'parent-copy'];
                if ($parentState === 'public') {
                    $expected[] = $newId;
                }
                $storedIds = array_column($store->indexedPages(), 'page_id');
                sort($expected);
                sort($storedIds);
                check($storedIds === $expected, 'Index retained old paths or lost descendants');
                foreach ($store->search([1.0, 0.0], 'test', 100) as $hit) {
                    $page = $app->page($hit->pageId);
                    check($page !== null && $hit->url === $page->url(), 'Stored citation URL is stale: ' . $hit->pageId);
                }
                if ($uuids) {
                    check($embeddings->calls === $callsBefore + 1, 'UUID metadata refresh re-embedded unchanged pages');
                }

                // An ordinary content edit must not queue the whole subtree.
                $parent->update(['text' => $text . ' Updated parent text.']);
                check($queue->pendingCount() === 1, 'Ordinary parent edit queued descendants');
                RagFactory::reset();
            }
        }
    }
    echo "Passed $checks lifecycle regression checks across 18 scenarios.\n";
} finally {
    RagFactory::reset();
    Dir::remove($root);
}
