<?php

namespace App\Services;

final class SignupResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?int    $signupId,
        public readonly ?int    $volunteerId,
        public readonly ?string $errorCode,
    ) {}

    public static function success(int $signupId, int $volunteerId): self
    {
        return new self(true, $signupId, $volunteerId, null);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, null, $errorCode);
    }
}
