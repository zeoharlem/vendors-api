<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Dtos\InitializePaymentDto;
use App\Modules\Payment\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentProviderInterface $paymentProvider,
        private readonly string $callbackUrl
    ) {
    }

    public function initialize(
        string $customerId,
        InitializePaymentDto $dto
    ): array {
        if ($customerId === '') {
            throw new DomainException(
                'Authenticated customer is required.'
            );
        }

        $order = $this->paymentRepository->findCustomerOrder(
            $customerId,
            $dto->orderId
        );

        if ($order === null) {
            throw new DomainException('Order not found.');
        }

        if ($order['status'] !== 'pending_payment') {
            throw new DomainException(
                'Payment can only be initialized for an order awaiting payment.'
            );
        }

        if (!in_array($order['payment_status'], ['pending', 'failed'], true)) {
            throw new DomainException(
                'This order cannot currently accept a payment.'
            );
        }

        $amountMinor = (int) $order['total_minor'];

        if ($amountMinor <= 0) {
            throw new DomainException(
                'Order total must be greater than zero.'
            );
        }

        $currency = strtoupper(trim($order['currency']));

        if ($currency !== 'NGN') {
            throw new DomainException(
                'The configured payment provider currently supports NGN only.'
            );
        }

        $email = trim((string) ($order['customer_email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException(
                'A valid customer email address is required for payment.'
            );
        }

        // Reuse an existing initialized payment.
        $existingPayment = $this->paymentRepository
            ->findActivePaymentForOrder($dto->orderId);

        if (
            $existingPayment !== null
            && $existingPayment['status'] === 'initialized'
            && !empty($existingPayment['authorization_url'])
            && (int) $existingPayment['amount_minor'] === $amountMinor
            && strtoupper($existingPayment['currency']) === $currency
        ) {
            return $this->formatPaymentResponse($existingPayment);
        }

        $reference = 'PAY-' . strtoupper(bin2hex(random_bytes(12)));

        $payment = $this->paymentRepository->createPendingPayment(
            orderId: $dto->orderId,
            provider: 'paystack',
            reference: $reference,
            amountMinor: $amountMinor,
            currency: $currency
        );

        try {
            $result = $this->paymentProvider->initializePayment(
                [
                    'email' => $email,
                    'amount' => $amountMinor,
                    'currency' => $currency,
                    'reference' => $reference,
                    'callback_url' => $this->callbackUrl,
                    'metadata' => [
                        'order_id' => $dto->orderId,
                        'customer_id' => $customerId,
                        'payment_id' => $payment['id'],
                    ],
                ]
            );

            if (($result['reference'] ?? null) !== $reference) {
                throw new RuntimeException(
                    'Payment provider returned an unexpected reference.'
                );
            }

            $this->paymentRepository->markPaymentInitialized(
                reference: $reference,
                authorizationUrl: $result['authorization_url'],
                accessCode: $result['access_code'],
                providerResponse: $result['provider_response']
            );

            $payment = $this->paymentRepository->findByReference($reference)
                ?? throw new RuntimeException(
                    'Initialized payment could not be retrieved.'
                );

            return $this->formatPaymentResponse($payment);

        } catch (Throwable $exception) {
            try {
                $this->paymentRepository->markPaymentInitializationFailed(
                    reference: $reference,
                    failureReason: $exception->getMessage()
                );
            } catch (Throwable) {
                // Preserve the original initialization exception.
            }

            throw $exception;
        }
    }

    public function getPayment(
        string $customerId,
        string $reference
    ): ?array {
        if ($customerId === '' || $reference === '') {
            throw new DomainException('Invalid payment request.');
        }

        $payment = $this->paymentRepository->findCustomerPayment(
            $customerId,
            $reference
        );

        return $payment !== null ? $this->formatPaymentResponse($payment) : null;
    }

    /**
     * Verify a payment directly with the configured payment provider.
     *
     * Used by the customer callback and webhook processing workflow.
     */
    public function verify(string $reference): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException('Payment reference is required.');
        }

        $payment = $this->paymentRepository->findByReference($reference);

        if ($payment === null) {
            throw new DomainException('Payment not found.');
        }

        // Do not settle a successful payment more than once.
        if ($payment['status'] === 'success') {
            return $this->formatPaymentResponse($payment);
        }

        if ($payment['provider'] !== 'paystack') {
            throw new DomainException(
                'Unsupported payment provider for this verification request.'
            );
        }

        $verification = $this->paymentProvider->verifyPayment($reference);

        if (($verification['reference'] ?? null) !== $reference) {
            throw new DomainException(
                'Payment reference does not match.'
            );
        }

        if (
            (int) ($verification['amount'] ?? 0)
            !== (int) $payment['amount_minor']
        ) {
            throw new DomainException(
                'Payment amount does not match.'
            );
        }

        if (
            strtoupper((string) ($verification['currency'] ?? ''))
            !== strtoupper((string) $payment['currency'])
        ) {
            throw new DomainException(
                'Payment currency does not match.'
            );
        }

        $status = strtolower((string) ($verification['status'] ?? ''));

        if ($status === 'success') {
            $transactionId = (string) (
                $verification['provider_transaction_id'] ?? ''
            );

            if ($transactionId === '') {
                throw new DomainException(
                    'Payment provider did not return a transaction ID.'
                );
            }

            $payment = $this->paymentRepository->confirmSuccessfulPayment(
                reference: $reference,
                providerTransactionId: $transactionId,
                providerResponse: $verification['provider_response'] ?? []
            );

            return $this->formatPaymentResponse($payment);
        }

        if ($status === 'failed') {
            $this->paymentRepository->markPaymentFailed(
                reference: $reference,
                failureReason: 'Paystack transaction failed.',
                providerResponse: $verification['provider_response'] ?? []
            );
        }

        $payment = $this->paymentRepository->findByReference($reference);

        if ($payment === null) {
            throw new DomainException('Payment not found.');
        }

        return $this->formatPaymentResponse($payment);
    }

    private function formatPaymentResponse(array $payment): array
    {
        return [
            'id' => $payment['id'],
            'order_id' => $payment['order_id'],
            'provider' => $payment['provider'],
            'reference' => $payment['reference'],
            'amount_minor' => (int) $payment['amount_minor'],
            'currency' => $payment['currency'],
            'status' => $payment['status'],
            'authorization_url' => $payment['authorization_url'],
            'access_code' => $payment['access_code'],
            'created_at' => $payment['created_at'],
        ];
    }


    /**
     * Request a refund for a vendor order.
     *
     * A null amount requests the full remaining refundable balance
     * of that vendor order.
     *
     * @return array<string, mixed>
     */
    public function refund(
        string $paymentReference,
        string $vendorOrderId,
        ?int $amountMinor,
        ?string $requestedBy,
        string $reason,
        string $idempotencyKey
    ): array {
        $paymentReference = trim($paymentReference);
        $vendorOrderId = trim($vendorOrderId);
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);

        if (
            $paymentReference === ''
            || $vendorOrderId === ''
            || $reason === ''
            || $idempotencyKey === ''
        ) {
            throw new InvalidArgumentException(
                'Payment reference, vendor order, reason and idempotency key are required.'
            );
        }

        if ($amountMinor !== null && $amountMinor <= 0) {
            throw new InvalidArgumentException(
                'Refund amount must be greater than zero.'
            );
        }

        /*
         * Derive a stable Paystack reference from the application
         * idempotency key. Retrying the same request produces the
         * same provider reference.
         */
        $providerReference = 'rf_' . substr(hash('sha256', $idempotencyKey), 0, 40);

        /*
         * Persist the refund and reserve its refundable balance
         * before making any external provider request.
         */
        $refund = $this->paymentRepository->prepareRefund(
            paymentReference: $paymentReference,
            vendorOrderId: $vendorOrderId,
            amountMinor: $amountMinor,
            providerReference: $providerReference,
            idempotencyKey: $idempotencyKey,
            reason: $reason,
            requestedBy: $requestedBy,
        );

        $refundId = (string) $refund['id'];

        /*
         * Do not submit an already submitted or completed refund again.
         */
        if ($refund['status'] !== 'pending') {
            return $refund;
        }

        /*
         * Atomically claim the refund.
         *
         * A false result means another request has already claimed it.
         */
        $claimed = $this->paymentRepository->markRefundProcessing(
            $refundId
        );

        if (!$claimed) {
            return $this->paymentRepository
                ->findRefundByIdempotencyKey($idempotencyKey)
                ?? throw new RuntimeException(
                    'The existing refund could not be retrieved.'
                );
        }

        /*
         * The repository returned the original provider transaction ID
         * and the exact amount calculated under database locks.
         */
        $transactionReference = trim(
            (string) $refund['provider_transaction_id']
        );

        $refundAmount = (int) $refund['amount_minor'];
        $currency = strtoupper((string) $refund['currency']);

        if ($transactionReference === '' || $refundAmount <= 0) {
            throw new RuntimeException(
                'The prepared refund contains invalid payment details.'
            );
        }

        try {
            $providerResult = $this->paymentProvider->refundPayment(
                transactionReference: $transactionReference,
                amountMinor: $refundAmount,
                currency: $currency,
                refundReference: $providerReference,
                customerNote: mb_substr($reason, 0, 500),
                merchantNote: sprintf(
                    'Vendor order refund: %s. Refund ID: %s.',
                    $vendorOrderId,
                    $refundId
                ),
            );

            /*
             * Verify the provider response before persisting it.
             */
            if (
                ($providerResult['refund_reference'] ?? null)
                !== $providerReference
                || ($providerResult['transaction_reference'] ?? null)
                !== $transactionReference
                || (int) ($providerResult['amount'] ?? 0)
                !== $refundAmount
                || strtoupper((string) ($providerResult['currency'] ?? ''))
                !== $currency
                || !is_string($providerResult['status'] ?? null)
                || !is_array($providerResult['provider_response'] ?? null)
            ) {
                throw new RuntimeException(
                    'The payment provider returned an invalid refund response.'
                );
            }

            return $this->paymentRepository->updateRefundProviderResult(
                refundId: $refundId,
                providerReference: $providerResult['refund_reference'],
                status: $providerResult['status'],
                providerResponse: $providerResult['provider_response'],
            );
        } catch (Throwable $exception) {
            /*
             * Do not mark this refund as failed.
             *
             * The provider may have received and accepted the request
             * even if our application did not receive its response.
             * Keep it processing for later reconciliation.
             */
            throw new RuntimeException(
                'The refund request could not be confirmed with the payment provider. '
                . 'The refund remains in processing and must be reconciled before retrying.',
                previous: $exception
            );
        }
    }

    public function refundVendorOrder(
        string $vendorOrderId,
        ?int $amountMinor,
        ?string $requestedBy,
        string $reason,
        string $idempotencyKey,
    ): array {
        $vendorOrderId = trim($vendorOrderId);
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);

        if ($vendorOrderId === '') {
            throw new InvalidArgumentException('Vendor order ID is required.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('Refund reason is required.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Refund idempotency key is required.');
        }

        if ($amountMinor !== null && $amountMinor <= 0) {
            throw new InvalidArgumentException(
                'Refund amount must be greater than zero.'
            );
        }

        $paymentReference = $this->paymentRepository
            ->findSuccessfulPaymentReferenceForVendorOrder($vendorOrderId);

        if ($paymentReference === null) {
            throw new RuntimeException(
                'No successful payment was found for this vendor order.'
            );
        }

        return $this->refund(
            paymentReference: $paymentReference,
            vendorOrderId: $vendorOrderId,
            amountMinor: $amountMinor,
            requestedBy: $requestedBy,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
        );
    }

}
