<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Providers;

use App\Modules\Payment\Domain\Contracts\WebhookSignatureVerifierInterface;

final class PaystackWebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function __construct(
        private readonly string $secretKey
    ) {
        if (trim($this->secretKey) === '') {
            throw new \InvalidArgumentException(
                'Paystack secret key is required.'
            );
        }
    }

    public function isValid(
        string $payload,
        string $signature
    ): bool {
        if ($payload === '' || $signature === '') {
            return false;
        }

        $expectedSignature = hash_hmac(
            'sha512',
            $payload,
            $this->secretKey
        );

        return hash_equals(
            $expectedSignature,
            $signature
        );
    }
}