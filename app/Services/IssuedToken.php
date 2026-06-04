<?php

namespace App\Services;

/**
 * Value object returned when a token is issued.
 * Contains only the raw token for immediate email link construction.
 */
class IssuedToken
{
    public function __construct(
        public readonly string $rawToken,
        public readonly int $tokenId,
    ) {}
}
