<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Domain\Repository;

interface VendorRepository
{
    public function findByUserId(string $userId): ?array;

    public function createForUser(
        string $userId,
        string $businessName,
        ?string $businessRegistrationNumber,
    ): array;

    public function updateForUser(
        string $userId,
        string $businessName,
        ?string $businessRegistrationNumber
    ): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findPendingForReview(int $limit, int $offset): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $vendorProfileId): ?array;

    /**
     * Approves a pending vendor profile.
     *
     * Returns null if the profile is not pending or does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function approvePending(
        string $vendorProfileId,
        string $reviewerId
    ): ?array;

    /**
     * Rejects a pending vendor profile.
     *
     * Returns null if the profile is not pending or does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function rejectPending(
        string $vendorProfileId,
        string $reviewerId,
        string $reason
    ): ?array;

    /**
     * Returns the owning user's ID.
     */
    public function findUserIdByProfileId(string $vendorProfileId): ?string;
}