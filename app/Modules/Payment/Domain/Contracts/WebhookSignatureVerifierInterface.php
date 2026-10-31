<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

interface WebhookSignatureVerifierInterface
{
    public function isValid(
        string $payload,
        string $signature
    ): bool;
}