<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

interface OutboxEventPublisherInterface
{
    /**
     * Records an event in the transactional outbox.
     *
     * Must participate in the caller's existing database transaction.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        string $idempotencyKey,
        array $payload,
    ): void;
}