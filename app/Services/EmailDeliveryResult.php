<?php

namespace App\Services;

/**
 * Value object for email delivery outcome.
 */
class EmailDeliveryResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly ?string $errorMessage = null,
    ) {}
}
