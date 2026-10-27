<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Repository;

interface StoreRepository
{
    public function createForVendor(
        string $vendorProfileId,
        string $name,
        string $slug,
        ?string $description,
    ): array;

    public function findBySlug(string $slug): ?array;

    public function slugExists(string $slug): bool;

    public function findPublicById(string $id): ?array;

    public function listPublic(int $limit, int $offset): array;

    public function findByVendorProfileId(
        string $vendorProfileId,
    ): array;

    /**
     * List stores awaiting administrator review.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPending(int $limit, int $offset): array;

    /**
     * Approve a store only if its current status is pending.
     *
     * @return array<string, mixed>|null
     */
    public function approvePending(
        string $storeId,
        string $adminUserId,
    ): ?array;

    /**
     * Reject a store only if its current status is pending.
     *
     * @return array<string, mixed>|null
     */
    public function rejectPending(
        string $storeId,
        string $adminUserId,
        string $reason,
    ): ?array;

    /**
     * Find a store by ID for administrator review.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $storeId): ?array;

    public function findVendorUserIdByStoreId(string $storeId,): ?string;
}
