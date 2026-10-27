<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation;

use Phalcon\Mvc\Router\Group;

final class StoreRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Store\\Presentation';
        $adminStoreNamespace = $namespace;

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

        $api->addGet(
            '/admin/stores/{id}',
            [
                'namespace' => $adminStoreNamespace,
                'controller' => 'AdminStore',
                'action' => 'show',
            ],
        )->setName('v1.admin.stores.show');

        $api->addPatch(
            '/admin/stores/{id}/approve',
            [
                'namespace' => $adminStoreNamespace,
                'controller' => 'AdminStore',
                'action' => 'approve',
            ],
        )->setName('v1.admin.stores.approve');

        $api->addPatch(
            '/admin/stores/{id}/reject',
            [
                'namespace' => $adminStoreNamespace,
                'controller' => 'AdminStore',
                'action' => 'reject',
            ],
        )->setName('v1.admin.stores.reject');

        $api->addGet('/stores/{id}', [
            'namespace' => $namespace,
            'controller' => 'Store',
            'action' => 'show',
        ])->setName('v1.stores.show');

        $api->addGet(
            '/admin/stores/pending',
            [
                'namespace' => $adminStoreNamespace,
                'controller' => 'AdminStore',
                'action' => 'pending',
            ],
        )->setName('v1.admin.stores.pending');

        $api->addGet('/stores/me', [
            'namespace' => $namespace,
            'controller' => 'Store',
            'action' => 'mine',
        ])->setName('v1.stores.mine');
    }
}
