<?php

namespace Fabby\Session;

use Kirby\Cms\App;

/**
 * Replaces the original AES-128-ECB nonce scheme (functions.php
 * createNonce/validateAjaxRequest) with Kirby's native CSRF token, backed
 * by Kirby's own session. Safe to change (unlike the response envelope or
 * PCM format): the nonce is a purely widget-internal contract never
 * touched by Unity or any external consumer.
 *
 * The client-visible shape is preserved: issue() still returns a bare
 * string, validate() still returns a bool the caller uses to reject with
 * the same "INVALID REQUEST" signal — so src/api/client.ts needs no
 * changes beyond the endpoint URLs.
 */
final class NonceService
{
    public function __construct(private readonly App $kirby)
    {
    }

    public function issue(): string
    {
        // Ensures a session exists so the CSRF token is bound to it.
        $this->kirby->session();

        return csrf();
    }

    public function validate(?string $token): bool
    {
        if (!$token) {
            return false;
        }

        return csrf($token) === true;
    }
}
