<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Repositories;

use App\Modules\Payout\Domain\Entities\Payout;

interface PayoutRepositoryInterface
{
    public function create(Payout $payout): Payout;

    public function findById(string $id): ?Payout;

    public function findByIdForUpdate(string $id): ?Payout;

    public function findByIdempotencyKey(string $idempotencyKey): ?Payout;

    public function findByProviderReference(
        string $provider,
        string $providerReference
    ): ?Payout;

    public function findByProviderTransferId(
        string $provider,
        string $providerTransferId
    ): ?Payout;

    public function markProcessing(string $id): bool;

    public function markSuccessful(
        string $id,
        ?string $providerTransferId,
        array $providerResponse
    ): bool;

    public function markFailed(
        string $id,
        string $reason,
        array $providerResponse
    ): bool;
}