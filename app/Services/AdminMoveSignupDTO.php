<?php

declare(strict_types=1);

namespace App\Services;

/**
 * DTO for an admin-initiated signup move — Story 5.12.
 *
 * A strongly-typed readonly class enforces the project constraint: no loose
 * arrays or scalar lists may cross the Controller→Service boundary for write
 * operations (project-context.md#Code_Quality).
 */
final readonly class AdminMoveSignupDTO
{
    public function __construct(
        public int  $sourceSignupId,
        public int  $targetSlotId,
        public int  $kermesseId,
        public int  $adminUserId,
        public bool $sendNotificationEmail,
    ) {}
}
