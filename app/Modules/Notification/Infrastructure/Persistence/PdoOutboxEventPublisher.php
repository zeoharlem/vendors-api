<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence;

use App\Modules\Notification\Domain\Contracts\OutboxEventPublisherInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoOutboxEventPublisher implements OutboxEventPublisherInterface
{
    public function __construct(
        private readonly AdapterInterface $db,
    ) {
    }

    public function publish(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        string $idempotencyKey,
        array $payload,
    ): void {
        $eventType = trim($eventType);
        $aggregateType = trim($aggregateType);
        $idempotencyKey = trim($idempotencyKey);

        if ($eventType === '') {
            throw new RuntimeException('Outbox event type cannot be empty.');
        }

        if ($aggregateType === '') {
            throw new RuntimeException('Outbox aggregate type cannot be empty.');
        }

        if ($idempotencyKey === '') {
            throw new RuntimeException('Outbox idempotency key cannot be empty.');
        }

        if (
            !filter_var($aggregateId, FILTER_VALIDATE_REGEXP, [
                'options' => [
                    'regexp' => '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i',
                ],
            ])
        ) {
            throw new RuntimeException('Outbox aggregate ID must be a valid UUID.');
        }

        try {
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode outbox event payload.',
                previous: $exception
            );
        }

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.outbox_events (
                    event_type,
                    aggregate_type,
                    aggregate_id,
                    idempotency_key,
                    payload,
                    status,
                    attempts,
                    available_at,
                    created_at
                )
                VALUES (
                    :event_type,
                    :aggregate_type,
                    :aggregate_id,
                    :idempotency_key,
                    CAST(:payload AS JSONB),
                    'pending',
                    0,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (idempotency_key) DO NOTHING
                RETURNING id
            SQL,
            [
                'event_type' => $eventType,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'idempotency_key' => $idempotencyKey,
                'payload' => $encodedPayload,
            ]
        );

        $inserted = $result->fetch();

        if ($inserted !== false) {
            return;
        }

        // An existing idempotency key is only acceptable when it represents
        // the same event and aggregate.
        $existingResult = $this->db->query(
            <<<'SQL'
                SELECT
                    event_type,
                    aggregate_type,
                    aggregate_id,
                    payload
                FROM public.outbox_events
                WHERE idempotency_key = :idempotency_key
                LIMIT 1
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
            ]
        );

        $existing = $existingResult->fetch();

        if ($existing === false) {
            throw new RuntimeException(
                'Unable to verify the existing outbox event after an idempotency conflict.'
            );
        }

        $existingPayload = is_string($existing['payload'])
            ? json_decode($existing['payload'], true, 512, JSON_THROW_ON_ERROR)
            : $existing['payload'];

        $sameEvent = $existing['event_type'] === $eventType
            && $existing['aggregate_type'] === $aggregateType
            && strtolower((string) $existing['aggregate_id']) === strtolower($aggregateId)
            && $existingPayload == $payload;

        if (!$sameEvent) {
            throw new RuntimeException(
                'Outbox idempotency key already exists with a different event payload.'
            );
        }
    }
}
