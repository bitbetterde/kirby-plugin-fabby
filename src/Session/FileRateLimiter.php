<?php

namespace Fabby\Session;

/**
 * Small atomic rate-limit store for a single shared Kirby filesystem.
 *
 * The file only contains short-lived counters and irreversible identifiers:
 * a SHA-256 of the Kirby session token and an HMAC of REMOTE_ADDR. Raw IP
 * addresses and session credentials are never persisted.
 */
final class FileRateLimiter
{
    public function __construct(
        private readonly string $statePath,
        private readonly string $secretPath,
        private readonly ?string $injectedSecret = null,
    ) {
    }

    /**
     * @param array{session: int, ip: int, daily: int} $limits
     */
    public function consume(
        string $action,
        string $sessionToken,
        string $remoteAddress,
        array $limits,
        ?int $now = null,
    ): RateLimitResult {
        $now ??= time();
        $secret = $this->injectedSecret ?? $this->secret();
        $session = hash('sha256', $sessionToken);
        $ip = hash_hmac('sha256', $remoteAddress !== '' ? $remoteAddress : 'unknown', $secret);
        $minute = intdiv($now, 60);
        $day = intdiv($now, 86400);

        $checks = [
            ['key' => "minute:session:$action:$session:$minute", 'limit' => $limits['session'], 'expires' => ($minute + 1) * 60],
            ['key' => "minute:ip:$action:$ip:$minute", 'limit' => $limits['ip'], 'expires' => ($minute + 1) * 60],
            ['key' => "day:global:$action:$day", 'limit' => $limits['daily'], 'expires' => ($day + 1) * 86400],
        ];

        return $this->withLockedState(function (array &$state) use ($checks, $now): RateLimitResult {
            $buckets = is_array($state['buckets'] ?? null) ? $state['buckets'] : [];

            foreach ($buckets as $key => $bucket) {
                if (!is_array($bucket) || (int) ($bucket['expires'] ?? 0) <= $now) {
                    unset($buckets[$key]);
                }
            }

            $retryAfter = 0;

            foreach ($checks as $check) {
                if ($check['limit'] <= 0) {
                    continue;
                }

                $count = (int) ($buckets[$check['key']]['count'] ?? 0);
                if ($count >= $check['limit']) {
                    $retryAfter = max($retryAfter, max(1, $check['expires'] - $now));
                }
            }

            if ($retryAfter > 0) {
                $state['buckets'] = $buckets;
                return new RateLimitResult(false, $retryAfter);
            }

            foreach ($checks as $check) {
                if ($check['limit'] <= 0) {
                    continue;
                }

                $buckets[$check['key']] = [
                    'count' => (int) ($buckets[$check['key']]['count'] ?? 0) + 1,
                    'expires' => $check['expires'],
                ];
            }

            $state = ['version' => 1, 'buckets' => $buckets];

            return new RateLimitResult(true);
        });
    }

    private function secret(): string
    {
        $this->ensureDirectory(dirname($this->secretPath));
        $handle = @fopen($this->secretPath, 'c+');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Rate-Limit-Secret ist nicht beschreibbar.');
        }

        try {
            rewind($handle);
            $secret = trim((string) stream_get_contents($handle));

            if ($secret === '') {
                $secret = bin2hex(random_bytes(32));
                rewind($handle);
                $truncated = ftruncate($handle, 0);
                $written = $truncated ? fwrite($handle, $secret) : false;

                if (!$truncated
                    || $written !== strlen($secret)
                    || !fflush($handle)) {
                    throw new \RuntimeException('Rate-Limit-Secret konnte nicht gespeichert werden.');
                }
            }

            @chmod($this->secretPath, 0o600);

            return $secret;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function withLockedState(callable $callback): RateLimitResult
    {
        $this->ensureDirectory(dirname($this->statePath));
        $handle = @fopen($this->statePath, 'c+');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Rate-Limit-Speicher ist nicht beschreibbar.');
        }

        try {
            rewind($handle);
            $raw = (string) stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($state)) {
                throw new \RuntimeException('Rate-Limit-Speicher enthält ungültige Daten.');
            }

            $result = $callback($state);
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            rewind($handle);
            $truncated = ftruncate($handle, 0);
            $written = $truncated ? fwrite($handle, $encoded) : false;

            if (!$truncated || $written !== strlen($encoded) || !fflush($handle)) {
                throw new \RuntimeException('Rate-Limit-Speicher konnte nicht aktualisiert werden.');
            }

            @chmod($this->statePath, 0o600);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Rate-Limit-Verzeichnis ist nicht beschreibbar.');
        }
    }
}
