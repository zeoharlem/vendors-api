<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Repositories;

interface VendorPayoutRecoveryRepository
{
    public function createPending(array $data): array;

    public function findByIdForUpdate(string $id): ?array;

    public function sumReservedForPayout(
        string $payoutId,
        ?string $excludeRecoveryId = null
    ): int;

    public function markConfirmed(
        string $id,
        string $adminId
    ): array;

    public function markRejected(
        string $id,
        string $adminId,
        ?string $reason
    ): array;
}