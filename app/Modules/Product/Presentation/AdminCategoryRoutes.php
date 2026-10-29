<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use Phalcon\Mvc\Router\Group;

final class AdminCategoryRoutes
{

    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Product\\Presentation';

        $api->addGet(
            '/admin/categories',
            [
                'namespace' => $namespace,
                'controller' => 'AdminCategory',
                'action' => 'index',
            ]
        )->setName('admin.categories.index');

        $api->addGet(
            '/admin/categories/{id:[0-9a-fA-F-]+}',
            [
                'namespace' => $namespace,
                'controller' => 'AdminCategory',
                'action' => 'show',
            ]
        )->setName('admin.categories.show');

        $api->addPost(
            '/admin/categories',
            [
                'namespace' => $namespace,
                'controller' => 'AdminCategory',
                'action' => 'create',
            ]
        )->setName('admin.categories.create');

        $api->addPatch(
            '/admin/categories/{id:[0-9a-fA-F-]+}',
            [
                'namespace' => $namespace,
                'controller' => 'AdminCategory',
                'action' => 'update',
            ]
        )->setName('admin.categories.update');

        $api->addPatch(
            '/admin/categories/{id:[0-9a-fA-F-]+}/activate',
            [
                'namespace' => $namespace,
                'controller' => 'AdminCategory',
                'action' => 'activate',
            ]
        )->setName('admin.categories.activate');

        $api->addPatch(
            '/admin/categories/{id:[0-9a-fA-F-]+}/deactivate',
            [
                'namespace' => $namespace,
                'controller' => 'AdminCategory',
                'action' => 'deactivate',
            ]
        )->setName('admin.categories.deactivate');
    }
}