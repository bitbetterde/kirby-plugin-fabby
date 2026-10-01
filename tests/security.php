<?php

/** Run with: php tests/security.php (or set FABBY_KIRBY_ROOT for a custom layout). */
$kirbyRoot = getenv('FABBY_KIRBY_ROOT') ?: dirname(__DIR__, 4);
require $kirbyRoot . '/kirby/bootstrap.php';
require dirname(__DIR__) . '/index.php';

use Fabby\Config\FabbyConfig;
use Fabby\Config\SettingsPage;
use Fabby\Rag\Chunker;
use Fabby\Rag\ContentExtractor;
use Fabby\Rag\EmbeddingProviderInterface;
use Fabby\Rag\Indexer;
use Fabby\Rag\IndexQueue;
use Fabby\Rag\SearchHitVisibility;
use Fabby\Rag\VectorStore;
use Fabby\Tools\KnowledgeSearchTool;
use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
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

$root = sys_get_temp_dir() . '/fabby-security-' . bin2hex(random_bytes(8));
mkdir($root . '/content/fabby-settings', 0700, true);
$settings = [
    'title' => 'Fabby',
    'system_prompt' => 'Public editorial prompt',
    'rag_enabled' => 'false',
];
foreach (['openai_api_key', 'llm_api_key', 'rag_embedding_api_key', 'elevenlabs_api_key', 'google_search_api_key'] as $key) {
    $settings[$key] = 'dummy-' . $key;
}
file_put_contents($root . '/content/fabby-settings/fabby-settings.txt', Kirby\Data\Data::encode($settings, 'txt'));

$makeApp = static fn (string $user, ?array $site = null): App => new App([
    'roots' => ['index' => $root, 'kirby' => $kirbyRoot . '/kirby'],
    'urls' => ['index' => 'https://example.test'],
    'options' => ['content.uuid' => false, 'api.allowImpersonation' => true],
    'roles' => [['name' => 'editor', 'title' => 'Editor']],
    'users' => [['id' => 'editor', 'email' => 'editor@example.test', 'role' => 'editor']],
    'user' => $user,
    'site' => $site,
]);

try {
    // Cover unsaved content as well as the latest version.
    $app = $makeApp('kirby');
    $page = $app->page('fabby-settings');
    check($page instanceof SettingsPage, 'Settings page model must be registered');
    $page->version('changes')->save(array_replace($settings, ['llm_api_key' => 'dummy-unsaved-key']));

    $app = $makeApp('editor');
    $page = $app->page('fabby-settings');
    check(!$app->user()->isAdmin(), 'Fixture must use a non-admin');
    $api = $app->api()->resolve($page)->toArray();
    foreach (['default', 'panel', 'any'] as $view) {
        $response = $app->api()->call('pages/fabby-settings', 'GET', ['query' => ['view' => $view]]);
        check($response['status'] === 'ok', 'Editor page API failed');
        check(!str_contains(json_encode($response), 'dummy-'), 'Page API view leaked a credential: ' . $view);
    }
    foreach (fabbyTechnikFields() as $field) {
        check(!array_key_exists($field, $api['content']), 'API exposed technical field: ' . $field);
        foreach ($page->panel()->versions() as $version) {
            check(!array_key_exists($field, $version), 'Panel exposed technical field: ' . $field);
        }
    }
    check($api['content']['system_prompt'] === $settings['system_prompt'], 'Editor lost editorial content');
    check((new FabbyConfig($app))->llmApiKey() === $settings['llm_api_key'], 'Redaction changed runtime credentials');
    try {
        $page->update(['llm_api_key' => 'editor-overwrite']);
        throw new RuntimeException('Editor could overwrite a protected key');
    } catch (PermissionException) {
        check(true, 'Editor write rejected');
    }
    $page->update(['system_prompt' => 'Updated editorial prompt']);
    check($app->page('fabby-settings')->content()->get('llm_api_key')->value() === $settings['llm_api_key'], 'Editorial save lost hidden credentials');

    $app = $makeApp('kirby');
    $page = $app->page('fabby-settings');
    check($app->api()->resolve($page)->toArray()['content']['llm_api_key'] === $settings['llm_api_key'], 'Admin lost API key access');
    check($page->panel()->versions()['changes']['llm_api_key'] === 'dummy-unsaved-key', 'Admin lost unsaved key access');
    $page->update(['llm_api_key' => 'dummy-admin-update']);
    check((new FabbyConfig($app))->llmApiKey() === 'dummy-admin-update', 'Admin key update failed');

    $app = $makeApp('kirby', ['children' => [
        ['slug' => 'fabby-settings', 'template' => 'fabby-settings', 'content' => $settings],
        ['slug' => 'source', 'content' => [
            'title' => 'Source', 'text' => str_repeat('Public workshop description. ', 10), 'related' => 'target',
        ], 'blueprint' => ['fields' => ['text' => ['type' => 'textarea'], 'related' => ['type' => 'pages']]]],
        ['slug' => 'target', 'content' => ['title' => 'Target', 'text' => str_repeat('Withdrawn test summary. ', 10)]],
    ]]);
    $source = $app->page('source');
    check($app->api()->resolve($source)->toArray()['content'] === Kirby\Form\Form::for($source)->toFormValues(), 'Ordinary page API changed');
    $extractor = new ContentExtractor();
    $store = new VectorStore($root . '/test.sqlite', '', 2, 'test');
    $queue = new IndexQueue($store);
    $embeddings = new class implements EmbeddingProviderInterface {
        public function embed(array $texts): array { return array_fill(0, count($texts), [1.0, 0.0]); }
        public function model(): string { return 'test'; }
        public function dimensions(): int { return 2; }
    };
    $indexer = new Indexer($app, $store, $embeddings, $extractor, new Chunker(), $queue);
    $indexer->indexPage($source);
    $visibility = new SearchHitVisibility($app, $extractor, $queue);
    $tool = new KnowledgeSearchTool($store, $embeddings, $visibility);
    $hits = $store->search([1.0, 0.0], 'test');
    check(count($hits) > 0 && $visibility($hits[0]), 'Unchanged indexed content must remain visible');
    check(str_contains($tool->execute(['query' => 'Withdrawn'], []), 'Withdrawn test summary'), 'Fixture must initially expose the related summary');

    $target = $app->page('target');
    foreach (['excluded', 'draft', 'deleted'] as $state) {
        $app->site()->children()->set('target', $target);
        $indexer->indexPage($source);
        $oldHits = $store->search([1.0, 0.0], 'test');
        $queue->clear();
        if ($state === 'deleted') {
            $app->site()->children()->remove('target');
        } else {
            $withdrawn = $state === 'draft'
                ? $target->clone(['isDraft' => true])
                : $target->clone(['content' => array_replace($target->content()->toArray(), ['fabby_exclude' => 'true'])]);
            $app->site()->children()->set('target', $withdrawn);
        }
        check(!$visibility($oldHits[0]), 'Stale hit visible after target ' . $state);
        check(!str_contains($tool->execute(['query' => 'Withdrawn'], []), 'Withdrawn test summary'), 'Tool leaked withdrawn summary: ' . $state);
        check($queue->pendingCount() === 1, 'Referring page must be queued once');
        $job = $queue->claim(1)[0];
        $queue->fail($job['page_key'], $job['revision'], 'Simulated retry');
        $visibility($oldHits[0]);
        check($queue->claim(1)[0]['attempts'] === 1, 'Search must not reset retry attempts');
        $indexer->processQueue();
        $freshHits = $store->search([1.0, 0.0], 'test');
        check($visibility($freshHits[0]), 'Refreshed public document must become visible');
        check(!$visibility($oldHits[0]), 'A concurrent replacement must not validate an old hit');
        check(!str_contains($freshHits[0]->text, 'Withdrawn test summary'), 'Refresh retained withdrawn summary');
    }

    // Ensure lexical-only hits carry the same freshness proof as vector hits.
    $fresh = $extractor->extract($source);
    $ftsStatus = $store->ftsDiagnostics();
    foreach ([true, false] as $fts) {
        (new ReflectionProperty($store, 'fts'))->setValue($store, $fts ? $ftsStatus : ['status' => 'unavailable', 'reason' => 'test']);
        $lexicalHits = $store->hybridSearch([0.0, 1.0], 'workshop', 'test', 5, 0.9);
        check(count($lexicalHits) > 0, 'Lexical-only fixture returned no hits');
        foreach ($lexicalHits as $hit) {
            check($hit->contentHash === $fresh->contentHash(), 'Lexical hit lost document hash');
        }
    }
    $queue->clear();
    $queue->enqueue($fresh->pageKey, $fresh->pageId, IndexQueue::OP_DELETE);
    $queue->enqueueIfMissing($fresh->pageKey, $fresh->pageId);
    check($queue->claim(1)[0]['operation'] === IndexQueue::OP_DELETE, 'Search refresh overwrote a pending deletion');
    $store->close();
    echo "Passed $checks security regression checks.\n";
} finally {
    Dir::remove($root);
}
