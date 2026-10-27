<?php

declare(strict_types=1);

namespace App\Modules\Notification\Presentation;

use Phalcon\Mvc\Router\Group;

final class NotificationRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Notification\\Presentation';

        $api->addGet(
            '/notifications',
            [
                'namespace' => $namespace,
                'controller' => 'notification',
                'action' => 'index',
            ],
        );

        $api->addPatch(
            '/notifications/{id}/read',
            [
                'namespace' => $namespace,
                'controller' => 'notification',
                'action' => 'markRead',
            ],
        );
    }
}