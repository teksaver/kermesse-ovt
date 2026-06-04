<?php

namespace App\Services;

/**
 * Value object returned after processing an owner validation request.
 */
class ValidationOutcome
{
    public const SUCCESS         = 'success';
    public const INVALID_TOKEN   = 'invalid_token';
    public const EXPIRED_PENDING = 'expired_pending';
    public const USED_TOKEN      = 'used_token';
    public const REVOKED_TOKEN   = 'revoked_token';
    public const ALREADY_ACTIVE  = 'already_active';
    public const ERROR           = 'error';

    public function __construct(
        public readonly string $status,
        public readonly ?int $ownerId = null,
        public readonly ?int $kermesseId = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
