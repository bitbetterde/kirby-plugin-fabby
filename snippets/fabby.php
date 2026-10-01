<?php

/**
 * Embeds the Fabby widget on a Kirby page.
 *
 *   <?= snippet('fabby') ?>
 *
 * Public pages receive this snippet automatically through the plugin's
 * page.render:after hook. Explicit calls remain supported for custom
 * placement; the marker below keeps the automatic hook from adding it twice.
 *
 * This is the in-CMS counterpart to that loader, not a replacement: pages
 * outside this Kirby install keep embedding init.min.js, and both end up
 * loading the same two files from the same plugin routes. What the snippet
 * adds is that a Kirby site needs no hand-written <script> tag and no
 * assets copied into its webroot.
 *
 * @var Kirby\Cms\App $kirby
 */

if (!(new Fabby\Config\FabbyConfig(kirby()))->widgetEnabled()) {
    return;
}

$assets = fabbyAssetUrls();

?>
<script data-fabby-widget="embed"><?= 'window.fabby_assets=' . $assets['json'] . ';' ?></script>
<link rel="stylesheet" href="<?= $assets['css'] ?>">
<script src="<?= $assets['js'] ?>"></script>
