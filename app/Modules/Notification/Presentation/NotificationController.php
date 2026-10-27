<?php

declare(strict_types=1);

namespace App\Modules\Notification\Presentation;

use App\Modules\Notification\Application\Dtos\NotificationResponseDto;
use App\Modules\Notification\Application\Services\NotificationService;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;

final class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     */
    public function indexAction(): Response
    {
        $userId = (string) $this->dispatcher->getParameter('userAuthId');

        $limit = (int) $this->request->getQuery('limit', 'int', 20);
        $offset = (int) $this->request->getQuery('offset', 'int', 0);

        $notifications = $this->notificationService->listForUser(
            $userId,
            $limit,
            $offset,
        );

        $data = array_map(
            static fn(NotificationResponseDto $notification): array => $notification->toArray(),
            $notifications,
        );

        return $this->jsonResponder->success($data);
    }

    /**
     * PATCH /api/v1/notifications/{id}/read
     */
    public function markReadAction(): Response
    {
        $userId = (string) $this->dispatcher->getParameter('userAuthId');
        $notificationId = (string) $this->dispatcher->getParameter('id');

        if ($notificationId === '') {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                'A notification ID is required.',
                400,
            );
        }

        $marked = $this->notificationService->markAsRead(
            $notificationId,
            $userId,
        );

        if (!$marked) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'The notification was not found for this user.',
                404,
            );
        }

        return $this->jsonResponder->success([
            'id' => $notificationId,
            'read' => true,
        ]);
    }
}