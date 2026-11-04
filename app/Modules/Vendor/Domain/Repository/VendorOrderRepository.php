<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Domain\Repository;

interface VendorOrderRepository
{
    public function findByIdForStore(
        string $vendorOrderId,
        string $storeId
    ): ?array;

    public function listByStore(
        string $storeId,
        ?string $status,
        int $limit,
        int $offset
    ): array;

    public function countByStore(
        string $storeId,
        ?string $status
    ): int;

    public function updateStatus(
        string $vendorOrderId,
        string $storeId,
        string $currentStatus,
        string $newStatus,
        array $additionalData = []
    ): ?array;

    public function confirmCustomerDelivery(
        string $customerId,
        string $vendorOrderId
    ): ?array;

    public function autoCompleteExpiredDeliveries(
        int $limit
    ): int;
}