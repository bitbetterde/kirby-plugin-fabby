<?php

namespace Fabby\Http\Controllers;

use Fabby\Config\FabbyConfig;
use Fabby\Http\CorsPolicy;
use Fabby\Http\ResponseEnvelope;
use Fabby\Session\FileRateLimiter;
use Fabby\Session\NonceService;
use Kirby\Cms\App;
use Kirby\Http\Response;

/** Issues the session-bound nonce as the legacy bare JSON string. */
final class InitController
{
    public static function handle(App $kirby): Response
    {
        $origin = CorsPolicy::requestOrigin();
        $headers = CorsPolicy::headers($kirby, true, $origin);

        try {
            return static::handleRequest($kirby, $origin, $headers);
        } catch (\Throwable $e) {
            error_log('[fabby-api] Init-Fehler (' . $e::class . '): ' . $e->getMessage());

            return static::json(
                ResponseEnvelope::error('init', '', 'Der Dienst ist vorübergehend nicht verfügbar.'),
                503,
                $headers
            );
        }
    }

    /** @param array<string, string> $headers */
    private static function handleRequest(App $kirby, ?string $origin, array $headers): Response
    {
        if (!CorsPolicy::permitsApiRequest($kirby, $origin)) {
            return static::json(
                ResponseEnvelope::error('init', '', 'Diese Herkunft ist nicht erlaubt.'),
                403
            );
        }

        $method = strtolower($kirby->request()->method());

        if ($method === 'options') {
            return new Response('', 'application/json', 204, $headers);
        }

        if ($method !== 'post') {
            return static::json('WRONG REQUEST', 405, $headers);
        }

        $config = new FabbyConfig($kirby);

        if ($config->rateLimitEnabled()) {
            try {
                $limit = (new FileRateLimiter(
                    $config->rateLimitStatePath(),
                    $config->rateLimitSecretPath()
                ))->consume(
                    'init',
                    '',
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    $config->rateLimits('init')
                );
            } catch (\Throwable $e) {
                error_log('[fabby-api] Rate-Limit-Speicher: ' . $e->getMessage());

                return static::json(
                    ResponseEnvelope::error('init', '', 'Der Dienst ist vorübergehend nicht verfügbar.'),
                    503,
                    $headers
                );
            }

            if (!$limit->allowed) {
                $headers['Retry-After'] = (string) $limit->retryAfter;

                return static::json(
                    ResponseEnvelope::error('init', '', 'Zu viele Anfragen. Bitte später erneut versuchen.'),
                    429,
                    $headers
                );
            }
        }

        $nonce = (new NonceService($kirby))->issue();

        return static::json($nonce, 200, $headers);
    }

    /** @param array<string, string> $headers */
    private static function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'application/json',
            $status,
            $headers
        );
    }
}
