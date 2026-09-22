<?php

namespace Fabby\Http\Controllers;

use Fabby\Config\FabbyConfig;
use Kirby\Cms\App;
use Kirby\Http\Response;

/**
 * Serves the Panel-configured widget appearance as a tiny JavaScript file.
 *
 * Why a JS file and not JSON: the host page's integration contract is fixed —
 * it embeds exactly one script, init.min.js, which is deployed on third-party
 * sites and must not change. So the settings cannot be injected as an inline
 * <script> into the host page. Serving them as JS that the widget bundle
 * loads keeps that contract intact and, unlike a fetch(), the values are in
 * place before the first React render — the audio bubble therefore renders in
 * its final shape instead of switching once the setting arrives.
 *
 * The response deliberately sets no CORS headers: it is loaded as a classic
 * <script>, which is not subject to the same-origin policy.
 */
final class ThemeController
{
    public static function handle(App $kirby): Response
    {
        $theme = (new FabbyConfig($kirby))->widgetTheme();

        // Leere Strings fallen raus, damit die JS-Seite ihren eigenen Default
        // behaelt, statt auf einen leeren String zu laufen. Nur Strings sind
        // gemeint: Bei einem bool waere `false` der aussagekraeftige Wert und
        // duerfte auf keinen Fall wegfallen.
        $theme = array_filter($theme, static fn (mixed $v): bool => $v !== '');

        // JSON_HEX_* is what keeps a content value from breaking out of the
        // <script> context (`</script>`, quotes, ampersands). Today every
        // value here comes from a closed set in FabbyConfig, so nothing
        // arbitrary can reach this point — the escaping stays because that
        // is a property of the current fields, not of the channel.
        $json = json_encode(
            (object) $theme,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return new Response(
            'window.fabby_theme=' . $json . ';',
            'application/javascript',
            200,
            // These settings are editable in the Panel, so a long cache would
            // hide changes. A short one still absorbs repeat views.
            ['Cache-Control' => 'public, max-age=60']
        );
    }
}
