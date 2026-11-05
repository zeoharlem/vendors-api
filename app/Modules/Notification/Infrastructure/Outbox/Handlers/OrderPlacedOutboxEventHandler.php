<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Outbox\Handlers;

use App\Modules\Notification\Application\Handlers\OrderPlacedEmailHandler;
use App\Modules\Notification\Domain\Contracts\OutboxEventHandlerInterface;
use App\Shared\Enums\EventTypes;
use RuntimeException;

final readonly class OrderPlacedOutboxEventHandler implements OutboxEventHandlerInterface
{
    public function __construct(
        private OrderPlacedEmailHandler $orderPlacedEmailHandler,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        if (($event['event_type'] ?? null) !== EventTypes::ORDER_PLACED->value) {
            throw new RuntimeException(
                'OrderPlacedOutboxEventHandler received an unsupported event type.',
            );
        }

        if (($event['aggregate_type'] ?? null) !== 'order') {
            throw new RuntimeException(
                'The order.placed event must have aggregate_type "order".',
            );
        }

        $this->orderPlacedEmailHandler->handle(
            eventId: (string) $event['id'],
            orderId: (string) $event['aggregate_id'],
        );
    }
}