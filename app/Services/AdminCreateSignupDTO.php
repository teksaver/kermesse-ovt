<?php

declare(strict_types=1);

namespace App\Services;

/**
 * DTO for an admin-initiated manual volunteer signup — Story 5.11.
 *
 * A strongly-typed readonly class enforces the project constraint: no loose
 * arrays or scalar lists may cross the Controller→Service boundary for write
 * operations (project-context.md#Code_Quality).
 */
final readonly class AdminCreateSignupDTO
{
    public function __construct(
        public int    $slotId,
        public int    $kermesseId,
        public int    $adminUserId,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public bool   $sendConfirmationEmail,
    ) {}
}
