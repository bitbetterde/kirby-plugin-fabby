<?php
/** Run with: php tests/panel.php */
$site = getenv('FABBY_KIRBY_ROOT') ?: dirname(__DIR__, 4);
require $site . '/kirby/bootstrap.php';
require dirname(__DIR__) . '/index.php';
$root = sys_get_temp_dir() . '/fabby-menu-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$cases = [
    ['panel', 'panel/pages/fabby-settings', 'site', false, true],
    ['panel', 'panel/pages/fabby-settings/', 'site', false, true],
    ['panel', 'panel/pages/fabby-settings/files/example.png', 'site', false, true],
    ['panel', 'panel/site', 'site', true, false],
    ['panel', 'panel/pages/home', 'site', true, false],
    ['panel', 'panel/pages/fabby-settings-copy', 'site', true, false],
    ['panel', 'panel/users', 'users', false, false],
    ['admin', 'admin/pages/fabby-settings', 'site', false, true],
    ['admin', 'admin/pages/home', 'site', true, false],
];
try {
    foreach ($cases as [$slug, $path, $current, $siteCurrent, $fabbyCurrent]) {
        $app = new Kirby\Cms\App([
            'roots' => ['index' => $root, 'kirby' => $site . '/kirby'],
            'urls' => ['index' => 'https://example.test/cms'],
            'options' => ['panel.slug' => $slug, 'content.uuid' => false],
            'path' => $path,
            'user' => 'kirby',
            'site' => ['children' => [['slug' => 'fabby-settings', 'template' => 'fabby-settings', 'content' => ['rag_enabled' => 'false']]]],
        ]);
        $areas = $app->load()->areas();
        $menu = new Kirby\Panel\Menu([], [], $current);
        foreach (['site' => $siteCurrent, 'fabby' => $fabbyCurrent] as $name => $expected) {
            $entry = $menu->entry(Kirby\Panel\Panel::area($name, $areas[$name]));
            if (($entry['current'] ?? false) !== $expected) {
                throw new RuntimeException("Incorrect active state for $name at $path");
            }
        }
        // Exercise the complete Panel view path, which resolves the active
        // area's current callback without the menu's $current argument.
        $panelAreas = [];
        foreach ($areas as $id => $area) {
            $panelAreas[$id] = Kirby\Panel\Panel::area($id, $area);
        }
        $data = Kirby\Panel\View::data(
            ['component' => 'k-page-view', 'props' => []],
            ['area' => $panelAreas[$current], 'areas' => $panelAreas]
        );
        $view = $data['$view']();
        if ($view['component'] !== 'k-page-view' || $view['code'] !== 200) {
            throw new RuntimeException('Panel view failed to render at ' . $path);
        }
        if (empty($areas['site']['views']) || empty($areas['site']['dialogs'])) {
            throw new RuntimeException('Site area lost its core views/dialogs');
        }
    }
    echo 'All ' . count($cases) . " menu scenarios passed.\n";
} finally {
    Kirby\Filesystem\Dir::remove($root);
}
