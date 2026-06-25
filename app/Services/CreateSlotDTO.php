<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Strongly-typed DTO for a slot creation request — Story 6.1.
 *
 * Enforces the project constraint (project-context.md#Code_Quality): no loose
 * associative arrays cross the Controller→Service boundary for write operations.
 * All times are already normalized to full datetime strings by the controller.
 */
final readonly class CreateSlotDTO
{
    public function __construct(
        public int    $kermesseId,
        public int    $standId,
        public string $startsAt,
        public string $endsAt,
        public int    $capacity,
    ) {}
}
