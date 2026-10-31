<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation\Routes;

use Phalcon\Mvc\Router\Group;
use App\Modules\Cart\Presentation\CartController;

final class CartRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Cart\\Presentation';

        $api->addGet('/cart', [
            'namespace' => $namespace,
            'controller' => 'Cart',
            'action' => 'index',
        ]);

        $api->addPost('/cart/items', [
            'namespace' => $namespace,
            'controller' => 'Cart',
            'action' => 'addItem',
        ]);

        $api->addPatch('/cart/items/{id:[0-9a-fA-F-]+}', [
            'namespace' => $namespace,
            'controller' => 'Cart',
            'action' => 'updateItem',
        ]);

        $api->addDelete('/cart/items/{id:[0-9a-fA-F-]+}', [
            'namespace' => $namespace,
            'controller' => 'Cart',
            'action' => 'removeItem',
        ]);
    }
}