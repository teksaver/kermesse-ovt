<?php

namespace App\Services;

/**
 * Value object representing the result of creating a kermesse.
 */
class CreationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $ownerId = 0,
        public readonly int $kermesseId = 0,
        public readonly ?EmailDeliveryResult $emailResult = null,
        public readonly ?string $errorCode = null,
    ) {}
}
