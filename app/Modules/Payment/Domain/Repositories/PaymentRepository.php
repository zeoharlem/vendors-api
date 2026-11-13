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
    ): array;

    /**
     * Create a durable refund record.
     *
     * The repository must:
     * - Lock the successful payment and relevant order records.
     * - Verify that the vendor order belongs to the payment's order.
     * - Calculate the remaining refundable amount when amountMinor is null.
     * - Prevent aggregate refunds from exceeding the original payment amount.
     * - Enforce the vendor-order refundable limit.
     * - Enforce idempotency.
     *
     * @return array<string, mixed>
     */
    public function prepareRefund(
        string $paymentReference,
        string $vendorOrderId,
        ?int $amountMinor,
        string $providerReference,
        string $idempotencyKey,
        string $reason,
        ?string $requestedBy
    ): array;

    /**
     * Mark a refund as being submitted to the provider.
     */
    public function markRefundProcessing(string $refundId): bool;

    /**
     * Retrieve a refund using its application idempotency key.
     *
     * @return array<string, mixed>|null
     */
    public function findRefundByIdempotencyKey(
        string $idempotencyKey
    ): ?array;

    /**
     * Persist the provider's refund response.
     *
     * Supported statuses must match the refunds table constraint.
     *
     * @param array<string, mixed> $providerResponse
     */
    public function updateRefundProviderResult(
        string $refundId,
        string $providerReference,
        string $status,
        array $providerResponse
    ): array;

    public function findSuccessfulPaymentReferenceForVendorOrder(
        string $vendorOrderId,
    ): ?string;

    public function findRefundForWebhook(
        ?string $providerReference,
        string $transactionReference,
        int $amountMinor,
        string $currency
    ): ?array;

    public function applyRefundWebhookUpdate(
        string $refundId,
        string $providerReference,
        string $status,
        array $providerResponse
    ): array;
}