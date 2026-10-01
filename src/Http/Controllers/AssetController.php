<?php

namespace Fabby\Http\Controllers;

use Fabby\Config\FabbyConfig;
use Fabby\Http\CorsPolicy;
use Kirby\Cms\App;
use Kirby\Http\Response;

/**
 * Serves the widget bundle and the Unity figure straight out of the plugin.
 *
 * Why the plugin ships these at all: without it, installing kirby-fabby is
 * not enough — four Unity files, the bundle and the stylesheet have to be
 * copied into the site's webroot by hand, and a <script> tag added to a
 * template. Everything the chatbot needs now travels with the plugin.
 *
 * Why a route and not Kirby's plugin `assets` mechanism: that path runs
 * through Response::file(), which reads the whole file into memory via
 * F::read() and derives the MIME type from the extension. Both are wrong
 * here. Measured on build.data.br (10 MB): readfile() peaks at 2.0 MB,
 * F::read() at 11.6 MB — with memory_limit 128M a handful of parallel
 * first-time loads would exhaust the pool. And the extension is `.br`,
 * which would yield neither application/wasm nor the Content-Encoding
 * header Unity's loader needs.
 */
final class AssetController
{
    /**
     * Suffix → Content-Type, in match order: the compound `.wasm.br` and
     * `.js.br` entries come first so a bare extension never wins over them.
     * See contentType() for why the decompressed type is what matters.
     */
    private const TYPES = [
        '.wasm.br' => 'application/wasm',
        '.js.br' => 'application/javascript',
        '.data.br' => 'application/octet-stream',
        '.symbols.json.br' => 'application/json',
        '.js' => 'application/javascript',
        '.css' => 'text/css',
        '.json' => 'application/json',
        '.mp4' => 'video/mp4',
        '.png' => 'image/png',
        '.jpg' => 'image/jpeg',
        '.svg' => 'image/svg+xml',
        '.woff2' => 'font/woff2',
    ];

    public static function handle(App $kirby, string $path): Response
    {
        $base = $kirby->plugin('bitbetter/fabby')?->root() . '/assets';
        $real = realpath($base);

        if ($real === false) {
            return new Response('Asset-Verzeichnis fehlt.', 'text/plain', 500);
        }

        $file = static::resolve($real, $path);

        if ($file === null) {
            return new Response('Nicht gefunden.', 'text/plain', 404);
        }

        // Legacy embeds load these files directly, without the Kirby snippet.
        // Keep the disabled response out of caches so adding a key takes effect
        // on the next request. Other assets (including the Panel) stay usable.
        if (
            in_array($file, [$real . '/widget/main.min.js', $real . '/widget/styles.min.css'], true)
            && !(new FabbyConfig($kirby))->widgetEnabled()
        ) {
            return new Response('', static::contentType($file), 200, ['Cache-Control' => 'no-store']);
        }

        // Streamed, not returned as a Response body: see the class docblock.
        // Kirby's Response has no streaming variant, so this writes the
        // headers itself and leaves the request here.
        static::stream($kirby, $file);
    }

    /**
     * Resolves a requested path inside the asset directory, or null.
     *
     * Split out from handle() so the traversal defence is unit-testable
     * without a running server: this is a security control, and a raw
     * `../` request is awkward to fire from a test (curl and most clients
     * normalise the path away before it ever reaches PHP).
     *
     * @param string $base Absolute, already realpath()-resolved.
     */
    public static function resolve(string $base, string $path): ?string
    {
        // realpath() collapses `..` before the comparison below, so an
        // escape attempt resolves to somewhere outside $base and is
        // rejected. A non-existent path yields false and is rejected too.
        $file = realpath($base . '/' . $path);

        // The separator matters: without it a sibling directory whose name
        // merely starts with the same characters (…/assets-backup) would
        // pass the prefix check.
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($file) ? $file : null;
    }

    /**
     * Content-Type for a file, by longest matching suffix.
     *
     * Unity's loader needs the type of the DECOMPRESSED content: the
     * browser strips Content-Encoding before the loader sees the bytes, so
     * build.wasm.br has to arrive as application/wasm. Deriving the type
     * from the `.br` extension — which is what Kirby's Response::file()
     * would do — breaks the figure.
     */
    public static function contentType(string $file): string
    {
        foreach (self::TYPES as $suffix => $candidate) {
            if (str_ends_with($file, $suffix)) {
                return $candidate;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * @return never
     */
    private static function stream(App $kirby, string $file): void
    {
        $type = static::contentType($file);

        // Anything Kirby or PHP already queued would end up in front of the
        // binary and corrupt it — the same failure mode as PHP 8.5
        // deprecation output that used to break res.json().
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove();

        // Must come after header_remove(): Unity fetches these files from the
        // widget host while the bundle executes on the embedding page.
        CorsPolicy::emitAssetHeaders($kirby);

        // Size and mtime, not a content hash: hashing build.data.br costs
        // 11.6 ms on every request, and both values change exactly when a
        // deploy replaces the file. Weak (W/) because the byte stream is
        // Brotli-encoded — this identifies the representation, not the
        // decoded bytes.
        $etag = sprintf('W/"%x-%x"', filesize($file), filemtime($file));

        // Without this the revalidating Unity files would re-download 17 MB
        // on every page load. With it the same request is a 304 and a few
        // hundred bytes.
        $known = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($known !== '' && $known === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: ' . static::cacheControl($file));
            exit;
        }

        http_response_code(200);
        header('Content-Type: ' . $type);

        if (str_ends_with($file, '.br')) {
            header('Content-Encoding: br');
        }

        // Unity's loader reports download progress from Content-Length and
        // sits at 0% without it.
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        header('Cache-Control: ' . static::cacheControl($file));

        readfile($file);
        exit;
    }

    /**
     * Widget assets must revalidate because their availability depends on
     * the configured API key, not just the file's version.
     *
     * The bundle and the stylesheet are requested by URLs the snippet
     * builds, so they carry a `?v=` mtime stamp (fabbyAssetUrls() in
     * index.php). Revalidation also covers legacy URLs without that stamp
     * and lets the handler suppress a previously enabled widget.
     *
     * The Unity files are NOT: the loader appends its own filenames to the
     * bare directory in `window.fabby_assets`, so their URLs never change
     * across a rebuild. Caching those immutably would leave visitors on a
     * stale 17 MB build with no way to invalidate it — and Unity keeps its
     * own copy in IndexedDB on top (webGLDataCaching), which survives even
     * a hard reload. They get revalidation instead: a 304 costs one
     * round-trip, a wrong build costs a support case.
     */
    public static function cacheControl(string $file): string
    {
        $versionable = str_contains($file, DIRECTORY_SEPARATOR . 'widget' . DIRECTORY_SEPARATOR);

        return $versionable
            ? 'public, no-cache'
            : 'public, max-age=0, must-revalidate';
    }
}
