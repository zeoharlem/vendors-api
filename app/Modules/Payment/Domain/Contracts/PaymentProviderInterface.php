<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

interface PaymentProviderInterface
{
    /**
     * Initialize a payment with the provider.
     *
     * @param array{
     *     email: string,
     *     amount: int,
     *     currency: string,
     *     reference: string,
     *     callback_url?: string,
     *     metadata?: array
     * } $data
     *
     * @return array{
     *     authorization_url: string,
     *     access_code: string|null,
     *     reference: string,
     *     provider_response: array
     * }
     */
    public function initializePayment(array $data): array;

    /**
     * Verify a payment directly with the provider.
     *
     * @return array{
     *     reference: string,
     *     amount: int,
     *     currency: string,
     *     status: string,
     *     provider_transaction_id: string|null,
     *     provider_response: array
     * }
     */
    public function verifyPayment(string $reference): array;
}