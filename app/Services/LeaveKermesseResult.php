<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Result DTO for RoleService::leaveKermesse().
 * Two distinct rejection codes require two distinct FR messages, so bool alone is insufficient.
 */
final readonly class LeaveKermesseResult
{
    public function __construct(
        public bool    $success,
        public ?string $errorCode,
    ) {}

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, $errorCode);
    }
}
