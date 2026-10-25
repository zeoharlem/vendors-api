<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation;

use Phalcon\Mvc\Router\Group;

final class VendorRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Vendor\\Presentation';

        $api->addPost('/vendors/profile', [
            'namespace' => $namespace,
            'controller' => 'Vendor',
            'action' => 'createProfile',
        ])->setName('v1.vendors.profile.create');

        $api->addGet('/vendors/me', [
            'namespace' => $namespace,
            'controller' => 'Vendor',
            'action' => 'me',
        ])->setName('v1.vendors.me');
    }
}