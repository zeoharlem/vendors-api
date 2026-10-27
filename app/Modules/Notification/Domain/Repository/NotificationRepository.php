<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Repository;

use App\Modules\Notification\Application\Dtos\CreateNotificationDto;
use App\Modules\Notification\Application\Dtos\NotificationResponseDto;

interface NotificationRepository
{
    public function create(CreateNotificationDto $notification): void;

    /**
     * @return array<int, NotificationResponseDto>
     */
    public function findForUser(string $userId, int $limit, int $offset): array;

    public function markAsRead(string $notificationId, string $userId): bool;
}
