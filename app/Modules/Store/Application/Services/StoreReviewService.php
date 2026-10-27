<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Services;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Store\Domain\Repository\StoreRepository;
use DomainException;
use Throwable;

final readonly class StoreReviewService
{
    public function __construct(
        private StoreRepository $storeRepository,
        private NotificationService $notificationService,
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
            $this->notifyVendorSafely(
                storeId: $storeId,
                store: $store,
                status: 'active',
            );
            return $store;
        }

        $this->assertStoreExistsAndIsPending($storeId);

        // Defensive fallback: assertStoreExistsAndIsPending throws otherwise.
        throw new DomainException('CONFLICT');
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
            $this->notifyVendorSafely(
                storeId: $storeId,
                store: $store,
                status: 'rejected',
                reason: $reason,
            );
            return $store;
        }

        $this->assertStoreExistsAndIsPending($storeId);

        throw new DomainException('STORE_REVIEW_CONFLICT');
    }

    private function notifyVendorSafely(
        string $storeId,
        array $store,
        string $status,
        ?string $reason = null,
    ): void {
        try {
            $vendorUserId = $this->storeRepository->findVendorUserIdByStoreId($storeId);

            if ($vendorUserId === null) {

                error_log(
                    sprintf(
                        'Unable to create store review notification: vendor not found for store %s',
                        $storeId,
                    ),
                );

                return;
            }

            $storeName = (string) ($store['name'] ?? 'Your store');

            if ($status === 'active') {
                $this->notificationService->notifyStoreApproved(
                    userId: $vendorUserId,
                    storeId: $storeId,
                    storeName: $storeName,
                );

                return;
            }

            if ($status === 'rejected' && $reason !== null) {
                $this->notificationService->notifyStoreRejected(
                    userId: $vendorUserId,
                    storeId: $storeId,
                    storeName: $storeName,
                    reason: $reason,
                );
            }
        } catch (Throwable $exception) {
            error_log(
                sprintf(
                    'Failed to create store review notification for store %s: %s with status %s',
                    $storeId,
                    $exception->getMessage(),
                    $status,
                ),
            );
        }
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