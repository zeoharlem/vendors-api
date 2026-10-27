<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation;

use Phalcon\Mvc\Router\Group;

final class AdminVendorRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Vendor\\Presentation';

        $api->addGet('/admin/vendors/pending', [
            'namespace' => $namespace,
            'controller' => 'adminvendor',
            'action' => 'pending',
        ]);

        $api->addGet('/admin/vendors/{id}', [
            'namespace' => $namespace,
            'controller' => 'adminvendor',
            'action' => 'show',
        ]);

        $api->addPatch('/admin/vendors/{id}/approve', [
            'namespace' => $namespace,
            'controller' => 'adminvendor',
            'action' => 'approve',
        ]);

        $api->addPatch('/admin/vendors/{id}/reject', [
            'namespace' => $namespace,
            'controller' => 'adminvendor',
            'action' => 'reject',
        ]);
    }
}