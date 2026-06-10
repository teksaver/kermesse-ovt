<?php

namespace App\Services;

final class SignupResult
{
    /**
     * @param array<string, mixed> $context  Optional extra data (e.g. conflicting slot times for overlap_conflict).
     */
    public function __construct(
        public readonly bool    $success,
        public readonly ?int    $signupId,
        public readonly ?int    $volunteerId,
        public readonly ?string $errorCode,
        public readonly array   $context = [],
    ) {}

    public static function success(int $signupId, int $volunteerId): self
    {
        return new self(true, $signupId, $volunteerId, null);
    }

    /** @param array<string, mixed> $context */
    public static function failure(string $errorCode, array $context = []): self
    {
        return new self(false, null, null, $errorCode, $context);
    }
}
