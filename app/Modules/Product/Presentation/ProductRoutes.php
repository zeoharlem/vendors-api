<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use Phalcon\Mvc\Router\Group;

final class ProductRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Product\\Presentation';

        $api->addGet('/health', [
            'namespace' => $namespace,
            'controller' => 'Health',
            'action' => 'index',
        ])->setName('v1.health');

        $api->addGet('/products', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'index',
        ])->setName('v1.products.index');

        $api->addGet('/products/{id:[0-9a-fA-F-]+}', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'show',
        ])->setName('v1.products.show');
    }
}
