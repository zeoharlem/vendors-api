<?php

declare(strict_types=1);

namespace App\Modules\Payout\Presentation\Routes;

use Phalcon\Mvc\Router\Group;

final class PayoutRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Payout\\Presentation\\Controllers';

        $api->addPost('/stores/{storeId}/payouts', [
            'namespace' => $namespace,
            'controller' => 'payout',
            'action' => 'request',
        ]);
    }
}