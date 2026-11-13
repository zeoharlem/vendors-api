<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Repositories;

use App\Modules\Dispute\Domain\Entities\Dispute;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use App\Modules\Dispute\Domain\ValueObjects\DisputeOrderContext;

interface DisputeRepositoryInterface
{
    public function create(Dispute $dispute): Dispute;

    public function save(Dispute $dispute): void;

    public function findById(string $disputeId): ?Dispute;

    /**
     * Must be called inside an active database transaction.
     */
    public function findByIdForUpdate(string $disputeId): ?Dispute;

    public function findByIdForCustomer(
        string $disputeId,
        string $customerId,
    ): ?Dispute;

    public function findByIdForStore(
        string $disputeId,
        string $storeId,
    ): ?Dispute;

    public function findActiveByVendorOrder(
        string $vendorOrderId,
    ): ?Dispute;

    /**
     * Must be called inside an active database transaction.
     */
    public function findActiveByVendorOrderForUpdate(
        string $vendorOrderId,
    ): ?Dispute;

    /**
     * @return list<Dispute>
     */
    public function listForCustomer(
        string $customerId,
        ?DisputeStatus $status,
        int $limit,
        int $offset,
    ): array;

    /**
     * @return list<Dispute>
     */
    public function listForStore(
        string $storeId,
        ?DisputeStatus $status,
        int $limit,
        int $offset,
    ): array;

    /**
     * @return list<Dispute>
     */
    public function listForAdmin(
        ?DisputeStatus $status,
        int $limit,
        int $offset,
    ): array;

    public function countForCustomer(
        string $customerId,
        ?DisputeStatus $status,
    ): int;

    public function countForStore(
        string $storeId,
        ?DisputeStatus $status,
    ): int;

    public function countForAdmin(
        ?DisputeStatus $status,
    ): int;

    public function findOrderContextForCustomer(
        string $vendorOrderId,
        string $customerId,
    ): ?DisputeOrderContext;
}
