<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence;

use App\Modules\Notification\Application\Dtos\CreateNotificationDto;
use App\Modules\Notification\Application\Dtos\NotificationResponseDto;
use App\Modules\Notification\Domain\Repository\NotificationRepository;
use Phalcon\Db\Enum;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoNotificationRepository implements NotificationRepository
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function create(CreateNotificationDto $notification): void
    {
        $this->db->execute(
            <<<'SQL'
            INSERT INTO public.notifications (
                user_id,
                type,
                title,
                message,
                data
            ) VALUES (
                :userId,
                :type,
                :title,
                :message,
                CAST(:data AS jsonb)
            )
        SQL,
            [
                'userId' => $notification->userId,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'data' => json_encode($notification->data, JSON_THROW_ON_ERROR),
            ],
        );
    }


    public function findForUser(
        string $userId,
        int $limit,
        int $offset,
    ): array {
        $rows = $this->db->fetchAll(
            <<<'SQL'
            SELECT
                id,
                type,
                title,
                message,
                data,
                read_at,
                created_at
            FROM public.notifications
            WHERE user_id = :userId
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
            OFFSET :offset
        SQL,
            Enum::FETCH_ASSOC,
            [
                'userId' => $userId,
                'limit' => max(1, min($limit, 100)),
                'offset' => max(0, $offset),
            ],
        );

        return array_map(
            static fn(array $row): NotificationResponseDto => NotificationResponseDto::fromArray($row),
            $rows,
        );
    }


    public function markAsRead(
        string $notificationId,
        string $userId,
    ): bool {
        $this->db->execute(
            <<<'SQL'
                UPDATE public.notifications
                SET read_at = COALESCE(read_at, NOW())
                WHERE id = :notificationId
                  AND user_id = :userId
            SQL,
            [
                'notificationId' => $notificationId,
                'userId' => $userId,
            ],
        );

        // See the affected-row caveat below.
        return $this->db->affectedRows() > 0;
    }
}