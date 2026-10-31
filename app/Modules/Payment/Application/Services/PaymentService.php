<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Dtos\InitializePaymentDto;
use App\Modules\Payment\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use DomainException;
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
            throw new DomainException('Authenticated customer is required.');
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

        // Reuse an existing initialized payment instead of creating duplicates.
        $existingPayment = $this->paymentRepository
            ->findActivePaymentForOrder($dto->orderId);

        if (
            $existingPayment !== null
            && $existingPayment['status'] === 'initialized'
            && !empty($existingPayment['authorization_url'])
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
            $result = $this->paymentProvider->initializePayment([
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
            ]);

            if ($result['reference'] !== $reference) {
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

        return $payment !== null
            ? $this->formatPaymentResponse($payment)
            : null;
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
}