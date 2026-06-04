<?php

namespace App\Services;

/**
 * Value object returned by AdminAuthorizationService.
 */
class AuthorizationResult
{
    public const AUTHORIZED         = 'authorized';
    public const NO_SESSION         = 'no_session';
    public const KERMESSE_MISMATCH  = 'kermesse_mismatch';
    public const PENDING_VALIDATION = 'pending_validation';
    public const ACCESS_DENIED      = 'access_denied';

    public function __construct(
        public readonly string $status,
    ) {}

    public function isAuthorized(): bool
    {
        return $this->status === self::AUTHORIZED;
    }
}
