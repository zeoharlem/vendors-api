<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Repositories;

interface PaymentRepository
{
    /**
     * Retrieve an order belonging to a customer.
     */
    public function findCustomerOrder(
        string $customerId,
        string $orderId
    ): ?array;

    /**
     * Find an existing pending or initialized payment for an order.
     */
    public function findActivePaymentForOrder(
        string $orderId
    ): ?array;

    /**
     * Create a pending payment record before contacting the provider.
     */
    public function createPendingPayment(
        string $orderId,
        string $provider,
        string $reference,
        int $amountMinor,
        string $currency
    ): array;

    /**
     * Save successful provider initialization details.
     */
    public function markPaymentInitialized(
        string $reference,
        string $authorizationUrl,
        ?string $accessCode,
        array $providerResponse
    ): void;

    /**
     * Record a failed initialization attempt.
     */
    public function markPaymentInitializationFailed(
        string $reference,
        string $failureReason,
        array $providerResponse = []
    ): void;

    /**
     * Find a payment by its unique provider reference.
     */
    public function findByReference(string $reference): ?array;

    public function findCustomerPayment(
        string $customerId,
        string $reference
    ): ?array;

    public function confirmSuccessfulPayment(
        string $reference,
        string $providerTransactionId,
        array $providerResponse
    ): array;

    public function markPaymentFailed(
        string $reference,
        string $failureReason,
        array $providerResponse = []
    ): void;
}