<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Outcome of a role invitation (Story 4.5).
 *
 * Mirrors SignupResult: a value object carrying the business outcome so the
 * controller renders the right French feedback without re-deriving state.
 * $emailSent is purely informational — a failed invitation email never undoes
 * the role assignment (the email_events row already traces the failure for ops).
 */
final class InvitationResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?int    $userId,
        public readonly ?string $role,
        public readonly ?string $errorCode,
        public readonly ?bool   $emailSent = null,
    ) {}

    public static function success(int $userId, string $role, ?bool $emailSent = null): self
    {
        return new self(true, $userId, $role, null, $emailSent);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, null, $errorCode);
    }
}
