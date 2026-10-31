<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Repositories;

interface PaymentEventRepository
{
    /**
     * Store a newly received provider event.
     *
     * Returns the inserted event, or null if the event
     * already exists for this provider.
     */
    public function recordReceivedEvent(
        string $provider,
        string $providerEventId,
        string $eventType,
        array $payload,
        ?string $paymentId = null
    ): ?array;

    public function markProcessed(string $eventId): void;

    public function markFailed(
        string $eventId,
        string $error
    ): void;
}