<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Routes;

use App\Modules\Order\Presentation\OrderController;
use Phalcon\Mvc\Router\Group;

final class OrderRoute
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Order\\Presentation\\Controllers';

        $api->addPost('/checkout', [
            'namespace' => $namespace,
            'controller' => 'Order',
            'action' => 'checkout',
        ]);

        $api->addGet('/orders/{id:[0-9a-fA-F-]+}', [
            'namespace' => $namespace,
            'controller' => 'Order',
            'action' => 'show',
        ]);
    }
}