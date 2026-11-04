<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation\Routes;

use Phalcon\Mvc\Router\Group;

final class VendorOrderRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Vendor\\Presentation\\Controllers';

        $api->addGet(
            '/vendor/orders',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'index',
            ]
        );

        $api->addGet(
            '/vendor/orders/{id}',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'show',
            ]
        );

        $api->addPost(
            '/vendor/orders/{id}/accept',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'accept',
            ]
        );

        $api->addPost(
            '/vendor/orders/{id}/processing',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'processing',
            ]
        );

        $api->addPost(
            '/vendor/orders/{id}/ready-to-ship',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'readyToShip',
            ]
        );

        $api->addPost(
            '/vendor/orders/{id}/ship',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'ship',
            ]
        );

        $api->addPost(
            '/vendor/orders/{id}/delivered',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'delivered',
            ]
        );

        $api->addPost(
            '/orders/{id}/confirm-delivery',
            [
                'namespace' => $namespace,
                'controller' => 'vendororder',
                'action' => 'confirmDelivery',
            ]
        );
    }
}