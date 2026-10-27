<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Services;

use App\Modules\Store\Domain\Repository\StoreRepository;
use DomainException;

final readonly class StoreReviewService
{
    public function __construct(
        private StoreRepository $storeRepository,
    ) {
    }

    public function listPending(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        return $this->storeRepository->findPending($limit, $offset);
    }

    public function getForReview(string $storeId): array
    {
        $store = $this->storeRepository->findById($storeId);

        if ($store === null) {
            throw new DomainException('STORE_NOT_FOUND');
        }

        return $store;
    }

    public function approve(string $storeId, string $adminUserId): array
    {
        $store = $this->storeRepository->approvePending(
            $storeId,
            $adminUserId,
        );

        if ($store !== null) {
            return $store;
        }

        $this->assertStoreExistsAndIsPending($storeId);

        // Defensive fallback: assertStoreExistsAndIsPending throws otherwise.
        throw new DomainException('STORE_REVIEW_CONFLICT');
    }

    public function reject(
        string $storeId,
        string $adminUserId,
        string $reason,
    ): array {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new DomainException('INVALID_REJECTION_REASON');
        }

        $store = $this->storeRepository->rejectPending(
            $storeId,
            $adminUserId,
            $reason,
        );

        if ($store !== null) {
            return $store;
        }

        $this->assertStoreExistsAndIsPending($storeId);

        throw new DomainException('STORE_REVIEW_CONFLICT');
    }

    private function assertStoreExistsAndIsPending(string $storeId): void
    {
        $store = $this->storeRepository->findById($storeId);

        if ($store === null) {
            throw new DomainException('STORE_NOT_FOUND');
        }

        if (($store['status'] ?? null) !== 'pending') {
            throw new DomainException('STORE_NOT_PENDING');
        }
    }
}