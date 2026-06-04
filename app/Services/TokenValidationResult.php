<?php

namespace App\Services;

/**
 * Value object returned when a token is validated.
 */
class TokenValidationResult
{
    public const VALID          = 'valid';
    public const INVALID_TOKEN  = 'invalid_token';
    public const EXPIRED_TOKEN  = 'expired_token';
    public const USED_TOKEN     = 'used_token';
    public const REVOKED_TOKEN  = 'revoked_token';

    /**
     * @param array<string, mixed>|null $tokenRow Full DB row, present when the token was found.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?array $tokenRow = null,
    ) {}

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }
}
