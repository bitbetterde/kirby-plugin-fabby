<?php

namespace Fabby\Session;

final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $retryAfter = 0,
    ) {
    }
}
