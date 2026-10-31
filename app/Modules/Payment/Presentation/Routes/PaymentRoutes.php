<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Routes;

use Phalcon\Mvc\Router\Group;

final class PaymentRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Payment\\Presentation\\Controllers';

        $api->addPost(
            '/payments/initialize',
            [
                'namespace' => $namespace,
                'controller' => 'Payment',
                'action' => 'initialize',
            ]
        );

        $api->addGet(
            '/payments/{reference:[A-Za-z0-9.\-=]+}',
            [
                'namespace' => $namespace,
                'controller' => 'Payment',
                'action' => 'show',
            ]
        );
    }
}