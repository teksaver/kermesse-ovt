<?php

namespace App\Services;

/**
 * Value object returned after processing a login/resend-link request.
 */
class LoginRequestResult
{
    public const CHECK_EMAIL      = 'check_email';
    public const VALIDATION_ERROR = 'validation_error';

    public function __construct(
        public readonly string $status,
        public readonly ?string $errorMessage = null,
    ) {}
}
