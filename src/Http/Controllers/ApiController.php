<?php

namespace Fabby\Http\Controllers;

use Fabby\Chat\FabbyService;
use Fabby\Chat\PromptBuilder;
use Fabby\Config\FabbyConfig;
use Fabby\Http\CorsPolicy;
use Fabby\Http\ResponseEnvelope;
use Fabby\Llm\LlmProviderFactory;
use Fabby\Session\FileRateLimiter;
use Fabby\Session\HoneypotGuard;
use Fabby\Session\NonceService;
use Fabby\Speech\ElevenLabsTts;
use Fabby\Speech\OpenAiWhisperStt;
use Fabby\Tools\ToolRegistry;
use Kirby\Cms\App;
use Kirby\Http\Response;

/** Public API boundary for chat, speech recognition and speech output. */
final class ApiController
{
    private const MAX_CHAT_CHARS = 30_000;
    private const MAX_TTS_CHARS = 4_000;
    private const MAX_AUDIO_ENCODED_BYTES = 14 * 1024 * 1024;
    private const MAX_AUDIO_DECODED_BYTES = 10 * 1024 * 1024;
    private const MAX_ID_CHARS = 64;
    private const AUDIO_PREFIX = 'data:audio/webm;base64,';

    public static function handle(App $kirby): Response
    {
        $origin = CorsPolicy::requestOrigin();
        $headers = CorsPolicy::headers($kirby, true, $origin);

        try {
            return static::handleRequest($kirby, $origin, $headers);
        } catch (\Throwable $e) {
            error_log('[fabby-api] Unerwarteter Fehler (' . $e::class . '): ' . $e->getMessage());

            return static::json(
                ResponseEnvelope::error('', '', 'Der Dienst ist vorübergehend nicht verfügbar.'),
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
                ResponseEnvelope::error('', '', 'Diese Herkunft ist nicht erlaubt.'),
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

        if (static::requestBodyExceedsServerLimit()) {
            return static::json(
                ResponseEnvelope::error('', '', 'Die Anfrage ist zu groß.'),
                413,
                $headers
            );
        }

        $post = $kirby->request()->body()->toArray();

        if (!(new HoneypotGuard())->passes($post)) {
            return static::json('INVALID REQUEST', 200, $headers);
        }

        if (!(new NonceService($kirby))->validate($post['nonce'] ?? null)) {
            return static::json('INVALID REQUEST', 200, $headers);
        }

        $action = (string) ($post['action'] ?? '');
        $id = (string) ($post['id'] ?? '');

        if ($action === '') {
            return static::json(ResponseEnvelope::error('', $id, 'No action given.'), 400, $headers);
        }

        if (!in_array($action, ['question_to_answer', 'audio_to_text', 'text_to_audio'], true)) {
            return static::json(
                ResponseEnvelope::error($action, $id, 'Unbekannte Aktion.'),
                400,
                $headers
            );
        }

        $payloadError = static::validatePayload($action, $id, $post);
        if ($payloadError !== null) {
            $status = in_array($payloadError, ['Das Audioformat ist ungültig.', 'Die Audiodaten sind ungültig.'], true)
                ? 400
                : 413;

            return static::json(
                ResponseEnvelope::error($action, $id, $payloadError),
                $status,
                $headers
            );
        }

        $config = new FabbyConfig($kirby);

        if ($config->rateLimitEnabled()) {
            try {
                $limit = (new FileRateLimiter(
                    $config->rateLimitStatePath(),
                    $config->rateLimitSecretPath()
                ))->consume(
                    $action,
                    (string) ($kirby->session()->token() ?? ''),
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    $config->rateLimits($action)
                );
            } catch (\Throwable $e) {
                error_log('[fabby-api] Rate-Limit-Speicher: ' . $e->getMessage());

                return static::json(
                    ResponseEnvelope::error($action, $id, 'Der Dienst ist vorübergehend nicht verfügbar.'),
                    503,
                    $headers
                );
            }

            if (!$limit->allowed) {
                $headers['Retry-After'] = (string) $limit->retryAfter;

                return static::json(
                    ResponseEnvelope::error($action, $id, 'Zu viele Anfragen. Bitte später erneut versuchen.'),
                    429,
                    $headers
                );
            }
        }

        try {
            $service = static::makeService($kirby, $config);
            $result = match ($action) {
                'question_to_answer' => $service->questionToAnswer((string) ($post['text'] ?? '')),
                'audio_to_text' => $service->audioToText((string) ($post['audio'] ?? '')),
                'text_to_audio' => $service->textToAudio((string) ($post['text'] ?? '')),
            };
        } catch (\Throwable $e) {
            error_log('[fabby-api] Provider-Fehler (' . $e::class . '): ' . $e->getMessage());

            return static::json(
                ResponseEnvelope::error($action, $id, 'Der externe Dienst ist vorübergehend nicht verfügbar.'),
                502,
                $headers
            );
        }

        if ($result === null) {
            $message = $service->error ?: 'Something went wrong.';

            return static::json(ResponseEnvelope::error($action, $id, $message), 200, $headers);
        }

        return static::json(ResponseEnvelope::success($action, $id, $result), 200, $headers);
    }

    private static function requestBodyExceedsServerLimit(): bool
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength <= 0) {
            return false;
        }

        // Allows framing/form fields around the largest encoded audio value.
        if ($contentLength > self::MAX_AUDIO_ENCODED_BYTES + 64 * 1024) {
            return true;
        }

        $configured = trim((string) ini_get('post_max_size'));
        if ($configured === '' || $configured === '-1' || $configured === '0') {
            return false;
        }

        $unit = strtolower(substr($configured, -1));
        $value = (int) $configured;
        $bytes = match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        return $bytes > 0 && $contentLength > $bytes;
    }

    /** @param array<string, mixed> $post */
    private static function validatePayload(string $action, string $id, array $post): ?string
    {
        if (mb_strlen($id) > self::MAX_ID_CHARS) {
            return 'Die Anfrage-ID ist zu lang.';
        }

        if ($action === 'question_to_answer'
            && mb_strlen((string) ($post['text'] ?? '')) > self::MAX_CHAT_CHARS) {
            return 'Der Chat-Kontext ist zu lang.';
        }

        if ($action === 'text_to_audio'
            && mb_strlen((string) ($post['text'] ?? '')) > self::MAX_TTS_CHARS) {
            return 'Der Text für die Sprachausgabe ist zu lang.';
        }

        if ($action !== 'audio_to_text') {
            return null;
        }

        $audio = rawurldecode((string) ($post['audio'] ?? ''));

        if (strlen($audio) > self::MAX_AUDIO_ENCODED_BYTES) {
            return 'Die Audiodatei ist zu groß.';
        }

        if (!str_starts_with($audio, self::AUDIO_PREFIX)) {
            return 'Das Audioformat ist ungültig.';
        }

        $decoded = base64_decode(substr($audio, strlen(self::AUDIO_PREFIX)), true);
        if ($decoded === false) {
            return 'Die Audiodaten sind ungültig.';
        }

        if (strlen($decoded) > self::MAX_AUDIO_DECODED_BYTES) {
            return 'Die Audiodatei ist zu groß.';
        }

        return null;
    }

    private static function makeService(App $kirby, FabbyConfig $config): FabbyService
    {
        return new FabbyService(
            $config,
            new LlmProviderFactory($config),
            new ToolRegistry($config),
            new PromptBuilder(),
            new ElevenLabsTts(
                $config->secret('elevenlabs_api_key'),
                $config->ttsVoiceId(),
                $config->ttsModel(),
                $config->ttsVoiceSettings()
            ),
            new OpenAiWhisperStt($config->secret('openai_api_key'))
        );
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
