<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Outbox\Handlers;

use App\Modules\Notification\Application\Handlers\PaymentStatusEmailHandler;
use App\Modules\Notification\Domain\Contracts\OutboxEventHandlerInterface;
use App\Shared\Enums\EventTypes;
use RuntimeException;

final readonly class PaymentSucceededOutboxEventHandler implements OutboxEventHandlerInterface
{
    public function __construct(
        private PaymentStatusEmailHandler $paymentStatusEmailHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        if (($event['event_type'] ?? null) !== EventTypes::PAYMENT_SUCCEEDED->value) {
            throw new RuntimeException(
                'Invalid event type for payment-succeeded handler.'
            );
        }

        if (($event['aggregate_type'] ?? null) !== 'payment') {
            throw new RuntimeException(
                'Invalid aggregate type for payment-succeeded handler.'
            );
        }

        $eventId = (string) ($event['id'] ?? '');
        $paymentId = (string) ($event['aggregate_id'] ?? '');

        if ($eventId === '' || $paymentId === '') {
            throw new RuntimeException(
                'Payment-succeeded event is missing its event ID or aggregate ID.'
            );
        }

        $this->paymentStatusEmailHandler->handle(
            eventId: $eventId,
            paymentId: $paymentId,
            expectedStatus: 'success',
        );
    }
}
