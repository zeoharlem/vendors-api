<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

interface EmailDeliveryRepositoryInterface
{
    public function createPending(
        string $idempotencyKey,
        ?string $outboxEventId,
        string $recipientEmail,
        ?string $recipientName,
        ?string $templateKey,
        string $subject,
        array $templateData = [],
    ): array;

    public function findById(string $deliveryId): ?array;

    public function claimForProcessing(string $deliveryId): bool;

    public function markSent(
        string $deliveryId,
        ?string $providerMessageId,
    ): bool;

    public function markFailed(
        string $deliveryId,
        string $error,
    ): bool;
}