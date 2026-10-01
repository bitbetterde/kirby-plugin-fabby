<?php

namespace Fabby\Http;

use Fabby\Config\FabbyConfig;
use Kirby\Cms\App;

/** Shared CORS policy for the public API and cross-origin Unity assets. */
final class CorsPolicy
{
    public static function requestOrigin(): ?string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        return is_string($origin) && $origin !== '' ? rtrim($origin, '/') : null;
    }

    public static function isAllowed(App $kirby, ?string $origin = null): bool
    {
        $origin ??= self::requestOrigin();

        if ($origin === null || preg_match('#^https?://#i', $origin) !== 1) {
            return false;
        }

        return in_array(rtrim($origin, '/'), self::allowedOrigins($kirby), true);
    }

    /**
     * Browser fetches must identify their origin. Server-to-server clients
     * without browser fetch metadata remain compatible with the old API.
     */
    public static function permitsApiRequest(App $kirby, ?string $origin = null): bool
    {
        $origin ??= self::requestOrigin();

        if ($origin !== null) {
            return self::isAllowed($kirby, $origin);
        }

        return self::looksLikeBrowserRequest() === false;
    }

    /** @return array<string, string> */
    public static function headers(App $kirby, bool $credentials, ?string $origin = null): array
    {
        $origin ??= self::requestOrigin();

        if (!self::isAllowed($kirby, $origin)) {
            return [];
        }

        $headers = [
            'Access-Control-Allow-Origin' => rtrim((string) $origin, '/'),
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Vary' => 'Origin',
        ];

        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    /** Assets are public, but fetch-based Unity loading still needs CORS. */
    public static function emitAssetHeaders(App $kirby): void
    {
        $origin = self::requestOrigin();

        if (!self::isAllowed($kirby, $origin)) {
            return;
        }

        foreach ([
            'Access-Control-Allow-Origin' => rtrim((string) $origin, '/'),
            'Vary' => 'Origin',
        ] as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /** @return string[] */
    private static function allowedOrigins(App $kirby): array
    {
        // The site's own origin is always allowed. Any further origin comes
        // from the Panel setting, so the plugin ships without fixed hosts.
        $origins = (new FabbyConfig($kirby))->corsAllowedOrigins();
        $index = (string) $kirby->url('index');
        $parts = parse_url($index);

        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $same = $parts['scheme'] . '://' . $parts['host'];
            if (isset($parts['port'])) {
                $same .= ':' . $parts['port'];
            }
            $origins[] = $same;
        }

        return array_values(array_unique(array_map(
            static fn (string $value): string => rtrim($value, '/'),
            $origins
        )));
    }

    private static function looksLikeBrowserRequest(): bool
    {
        if (isset($_SERVER['HTTP_SEC_FETCH_MODE']) || isset($_SERVER['HTTP_SEC_FETCH_SITE'])) {
            return true;
        }

        return str_contains(strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'mozilla/');
    }
}
