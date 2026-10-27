<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Application\Dtos\CreateNotificationDto;
use App\Modules\Notification\Application\Dtos\NotificationResponseDto;
use App\Modules\Notification\Domain\Repository\NotificationRepository;

final readonly class NotificationService
{
    public function __construct(
        private NotificationRepository $repository,
    ) {
    }

    /**
     * @return array<int, NotificationResponseDto>
     */
    public function listForUser(
        string $userId,
        int $limit = 20,
        int $offset = 0,
    ): array {
        return $this->repository->findForUser(
            $userId,
            max(1, min($limit, 100)),
            max(0, $offset),
        );
    }

    public function markAsRead(
        string $notificationId,
        string $userId,
    ): bool {
        return $this->repository->markAsRead(
            $notificationId,
            $userId,
        );
    }

    public function notifyStoreApproved(
        string $userId,
        string $storeId,
        string $storeName,
    ): void {
        $this->repository->create(
            new CreateNotificationDto(
                userId: $userId,
                type: 'store.approved',
                title: 'Your store has been approved',
                message: sprintf(
                    'Your store "%s" has been approved and is now active.',
                    $storeName,
                ),
                data: [
                    'store_id' => $storeId,
                    'status' => 'active',
                ],
            ),
        );
    }

    public function notifyStoreRejected(
        string $userId,
        string $storeId,
        string $storeName,
        string $reason,
    ): void {
        $this->repository->create(
            new CreateNotificationDto(
                userId: $userId,
                type: 'store.rejected',
                title: 'Your store needs attention',
                message: sprintf(
                    'Your store "%s" was not approved. Reason: %s',
                    $storeName,
                    $reason,
                ),
                data: [
                    'store_id' => $storeId,
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ],
            ),
        );
    }
}