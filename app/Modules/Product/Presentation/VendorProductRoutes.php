<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use Phalcon\Mvc\Router\Group;

final class VendorProductRoutes
{
    public static function register(Group $api): void
    {
        $api->addPost(
            '/vendor/products',
            [
                'namespace' => 'App\\Modules\\Product\\Presentation',
                'controller' => 'VendorProduct',
                'action' => 'create',
            ]
        )->setName('vendor.products.create');
    }
}