<?php

/** Run with: php tests/widget.php (or set FABBY_KIRBY_ROOT). */
$kirbyRoot = getenv('FABBY_KIRBY_ROOT') ?: dirname(__DIR__, 4);
require $kirbyRoot . '/kirby/bootstrap.php';
require dirname(__DIR__) . '/index.php';

use Fabby\Config\FabbyConfig;
use Fabby\Http\Controllers\AssetController;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;

$root = sys_get_temp_dir() . '/fabby-widget-' . bin2hex(random_bytes(8));
$envNames = ['FABBY_LLM_API_KEY', 'FABBY_OPENAI_API_KEY'];
$savedEnv = [];
foreach ($envNames as $name) {
    $savedEnv[$name] = getenv($name);
    putenv($name);
}
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

$cases = [
    'missing' => [[], [], [], false],
    'empty' => [['llm_api_key' => '', 'openai_api_key' => ''], [], [], false],
    'whitespace' => [['llm_api_key' => " \t\n"], [], [], false],
    'speech-only' => [['elevenlabs_api_key' => 'dummy-speech-key', 'rag_embedding_api_key' => 'dummy-embedding-key'], [], [], false],
    'panel-key' => [['llm_api_key' => 'dummy-llm-key'], [], [], true],
    'legacy-panel-key' => [['openai_api_key' => 'dummy-openai-key'], [], [], true],
    'empty-with-fallback' => [['llm_api_key' => '', 'openai_api_key' => 'dummy-openai-key'], [], [], true],
    'option-key' => [[], ['bitbetter.fabby.llm_api_key' => 'dummy-option-key'], [], true],
    'legacy-option-key' => [[], ['bitbetter.fabby.openai_api_key' => 'dummy-option-key'], [], true],
    'environment-key' => [[], [], ['FABBY_LLM_API_KEY' => 'dummy-environment-key'], true],
    'legacy-environment-key' => [[], [], ['FABBY_OPENAI_API_KEY' => 'dummy-environment-key'], true],
];

try {
    check(AssetController::cacheControl(dirname(__DIR__) . '/assets/widget/main.min.js') === 'public, no-cache', 'Enabled widget assets must revalidate after key changes');
    foreach ($cases as $name => [$settings, $options, $environment, $enabled]) {
        foreach ($envNames as $envName) {
            putenv(isset($environment[$envName]) ? $envName . '=' . $environment[$envName] : $envName);
        }
        $fixture = $root . '/' . $name;
        mkdir($fixture . '/site/templates', 0700, true);
        $html = '<html><body><main>Public page</main></body></html>';
        file_put_contents($fixture . '/site/templates/default.php', $html);
        $app = new App([
            'roots' => ['index' => $fixture, 'kirby' => $kirbyRoot . '/kirby'],
            'urls' => ['index' => 'https://example.test'],
            'options' => array_replace(['content.uuid' => false], $options),
            'site' => ['children' => [
                ['slug' => 'home', 'template' => 'default', 'content' => ['title' => 'Home']],
                ['slug' => 'fabby-settings', 'template' => 'fabby-settings', 'content' => array_replace(['rag_enabled' => 'false'], $settings)],
            ]],
        ]);
        check((new FabbyConfig($app))->widgetEnabled() === $enabled, $name . ': Wrong widget availability');
        $snippet = (string) $app->snippet('fabby');
        $rendered = $app->page('home')->render();
        if ($enabled) {
            check(substr_count($snippet, 'data-fabby-widget="embed"') === 1, $name . ': Snippet did not emit the widget');
            check(str_contains($snippet, '/fabby/assets/widget/main.min.js'), $name . ': Widget script missing');
            check(substr_count($rendered, 'data-fabby-widget="embed"') === 1, $name . ': Automatic injection missing');
            check(fabbyInjectWidget($rendered, 'html', $app->page('home')) === $rendered, $name . ': Widget injected twice');
        } else {
            check($snippet === '', $name . ': Disabled snippet emitted output');
            check($rendered === $html, $name . ': Disabled hook changed page HTML');
            check(fabbyInjectWidget('<main>Public fragment</main>', 'html', $app->page('home')) === '<main>Public fragment</main>', $name . ': Disabled hook changed fragment HTML');
            foreach (['widget/main.min.js' => 'application/javascript', 'widget/styles.min.css' => 'text/css', 'widget/../widget/main.min.js' => 'application/javascript'] as $path => $type) {
                $response = AssetController::handle($app, $path);
                check($response->body() === '' && $response->code() === 200, $name . ': Legacy embed received widget assets');
                check($response->type() === $type, $name . ': Wrong disabled asset MIME type');
                check($response->header('Cache-Control') === 'no-store', $name . ': Disabled asset response could be cached');
            }
        }
        check(!str_contains($snippet . $rendered, 'dummy-'), $name . ': Frontend leaked a credential');
        check(fabbyInjectWidget($html, 'json', $app->page('home')) === $html, $name . ': Non-HTML response changed');
        check(fabbyInjectWidget($html, 'html', $app->page('fabby-settings')) === $html, $name . ': Settings page received frontend assets');
        $app->impersonate('kirby');
        check($app->page('fabby-settings')->blueprint()->tab('technik') !== null, $name . ': Panel settings became unavailable');
    }
    echo "Passed $checks widget regression checks across " . count($cases) . " key configurations.\n";
} finally {
    foreach ($savedEnv as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    Dir::remove($root);
}
