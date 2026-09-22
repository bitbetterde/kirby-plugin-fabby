<?php

namespace Fabby\Session;

/**
 * Bot trap ported verbatim from functions.php::validateAjaxRequest. A
 * legitimate client (src/api/client.ts) never sends a `company` field;
 * any request that does is rejected.
 */
final class HoneypotGuard
{
    public function passes(array $postData): bool
    {
        return empty($postData['company']);
    }
}
