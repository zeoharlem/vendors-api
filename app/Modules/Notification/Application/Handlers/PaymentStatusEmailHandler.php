<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Handlers;

use App\Modules\Notification\Application\Messages\SendEmailMessage;
use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class PaymentStatusEmailHandler
{
    public function __construct(
        private AdapterInterface $db,
        private EmailDeliveryRepositoryInterface $deliveryRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Creates and queues a payment-status email.
     *
     * @throws RuntimeException
     */
    public function handle(
        string $eventId,
        string $paymentId,
        string $expectedStatus,
    ): void {
        if (
            trim($eventId) === ''
            || trim($paymentId) === ''
        ) {
            throw new RuntimeException(
                'Outbox event ID and payment ID are required.'
            );
        }

        if (!in_array($expectedStatus, ['success', 'failed'], true)) {
            throw new RuntimeException(
                'Unsupported payment notification status.'
            );
        }

        $payment = $this->db->query(
            <<<'SQL'
                SELECT
                    p.id,
                    p.order_id,
                    p.provider,
                    p.reference,
                    p.amount_minor,
                    p.currency,
                    p.status,
                    p.failure_reason,
                    o.order_number,
                    u.email,
                    u.first_name,
                    u.last_name
                FROM public.payments p
                INNER JOIN public.orders o ON o.id = p.order_id
                INNER JOIN public.users u ON u.id = o.customer_id
                WHERE p.id = :payment_id
                LIMIT 1
            SQL,
            [
                'payment_id' => $paymentId,
            ],
        )->fetch();

        if (!$payment) {
            throw new RuntimeException(
                sprintf(
                    'Payment "%s" could not be found.',
                    $paymentId,
                )
            );
        }

        // Do not send stale notifications when the payment has
        // subsequently moved to another status.
        if ($payment['status'] !== $expectedStatus) {
            return;
        }

        $recipientEmail = trim((string) $payment['email']);

        if (
            filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException(
                sprintf(
                    'A valid customer email is missing for payment "%s".',
                    $paymentId,
                )
            );
        }

        $customerName = trim(
            (string) $payment['first_name']
            . ' '
            . (string) $payment['last_name']
        );

        $orderNumber = trim((string) $payment['order_number']);

        if ($orderNumber === '') {
            throw new RuntimeException(
                sprintf(
                    'Order number is missing for payment "%s".',
                    $paymentId,
                )
            );
        }

        $isSuccessful = $expectedStatus === 'success';

        $templateKey = $isSuccessful
            ? 'payment_success'
            : 'payment_failed';

        $subject = $isSuccessful
            ? 'Payment received for order ' . $orderNumber
            : 'Payment unsuccessful for order ' . $orderNumber;

        $templateData = [
            'customer_name' => $customerName,
            'order_number' => $orderNumber,
            'reference' => (string) $payment['reference'],
            'currency' => (string) $payment['currency'],
            'amount_minor' => (int) $payment['amount_minor'],
        ];

        if (!$isSuccessful) {
            $templateData['failure_reason'] = trim(
                (string) ($payment['failure_reason'] ?? '')
            ) ?: 'The payment provider reported that the transaction was unsuccessful.';
        }

        // Event-specific idempotency prevents duplicate deliveries
        // when the outbox event is retried or replayed.
        $delivery = $this->deliveryRepository->createPending(
            idempotencyKey: $templateKey . ':' . $eventId,
            outboxEventId: $eventId,
            recipientEmail: $recipientEmail,
            recipientName: $customerName !== '' ? $customerName : null,
            templateKey: $templateKey,
            subject: $subject,
            templateData: $templateData,
        );

        $deliveryId = $delivery['id'] ?? null;

        if (
            !is_string($deliveryId)
            || trim($deliveryId) === ''
        ) {
            throw new RuntimeException(
                'The email delivery repository did not return a valid delivery ID.'
            );
        }

        $this->messageBus->dispatch(
            new SendEmailMessage($deliveryId)
        );
    }
}
