<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Repositories;

interface VendorPayoutAccountRepositoryInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForStore(string $storeId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForStore(string $accountId, string $storeId): ?array;

    /**
     * Persists only verified account details.
     *
     * @return array<string, mixed>
     */
    public function create(
        string $storeId,
        string $recipientCode,
        string $bankCode,
        string $bankName,
        string $accountName,
        string $accountNumberLast4,
        string $currency,
        bool $makeDefault
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function setDefault(string $accountId, string $storeId): array;

    public function disable(string $accountId, string $storeId): void;
}
