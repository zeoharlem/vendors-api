<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Outbox\Handlers;

use App\Modules\Notification\Application\Handlers\VendorOrderEmailHandler;
use App\Modules\Notification\Domain\Contracts\OutboxEventHandlerInterface;
use App\Shared\Enums\EventTypes;
use JsonException;
use RuntimeException;

final readonly class VendorOrderStatusOutboxEventHandler implements OutboxEventHandlerInterface
{
    public function __construct(
        private VendorOrderEmailHandler $emailHandler,
        private EventTypes $expectedEventType,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['event_type'] ?? null;
        $aggregateType = $event['aggregate_type'] ?? null;
        $aggregateId = $event['aggregate_id'] ?? null;

        if (
            !is_string($eventId)
            || $eventId === ''
            || $eventType !== $this->expectedEventType->value
            || $aggregateType !== 'vendor_order'
            || !is_string($aggregateId)
            || $aggregateId === ''
        ) {
            throw new RuntimeException(
                'Invalid vendor order outbox event.'
            );
        }

        $payload = $event['payload'] ?? null;

        if (is_string($payload)) {
            try {
                $payload = json_decode(
                    $payload,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    'Vendor order outbox event contains invalid JSON payload.',
                    previous: $exception,
                );
            }
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException(
                'Vendor order outbox event payload must be an object.'
            );
        }

        if (($payload['vendor_order_id'] ?? null) !== $aggregateId) {
            throw new RuntimeException(
                'Vendor order event payload does not match its aggregate ID.'
            );
        }

        $this->emailHandler->handle(
            eventId: $eventId,
            vendorOrderId: $aggregateId,
            eventType: $this->expectedEventType,
            eventPayload: $payload,
        );
    }
}