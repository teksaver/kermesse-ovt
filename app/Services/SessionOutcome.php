<?php

namespace App\Services;

/**
 * Value object returned after consuming an owner login token.
 */
class SessionOutcome
{
    public const SUCCESS       = 'success';
    public const INVALID_TOKEN = 'invalid_token';
    public const EXPIRED_TOKEN = 'expired_token';
    public const USED_TOKEN    = 'used_token';
    public const REVOKED_TOKEN = 'revoked_token';
    public const ERROR         = 'error';

    public function __construct(
        public readonly string $status,
        public readonly ?int $ownerId = null,
        public readonly ?int $kermesseId = null,
        public readonly ?int $tokenId = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
