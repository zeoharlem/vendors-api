<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use Phalcon\Mvc\Router\Group;

final class CategoryRoutes
{
    public static function register(Group $api): void
    {
        $api->addGet(
            '/categories',
            [
                'namespace' => 'App\\Modules\\Product\\Presentation',
                'controller' => 'category',
                'action' => 'index',
            ]
        )->setName('categories.active');
    }
}