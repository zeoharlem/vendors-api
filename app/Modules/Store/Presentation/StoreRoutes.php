<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation;

use Phalcon\Mvc\Router\Group;

final class StoreRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Store\\Presentation';

        $api->addPost('/stores', [
            'namespace' => $namespace,
            'controller' => 'Store',
            'action' => 'create',
        ])->setName('v1.stores.create');

        $api->addGet('/stores', [
            'namespace' => $namespace,
            'controller' => 'Store',
            'action' => 'index',
        ])->setName('v1.stores.index');

        $api->addGet('/stores/{id}', [
            'namespace' => $namespace,
            'controller' => 'Store',
            'action' => 'show',
        ])->setName('v1.stores.show');

        $api->addGet('/stores/me', [
            'namespace' => $namespace,
            'controller' => 'Store',
            'action' => 'mine',
        ])->setName('v1.stores.mine');
    }
}
