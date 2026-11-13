<?php

declare(strict_types=1);

namespace App\Modules\Payout\Presentation\Routes;

use Phalcon\Mvc\Router\Group;

final class PayoutAccountRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Payout\\Presentation\\Controllers';

        $api->addGet(
            '/payout/banks',
            [
                'namespace' => $namespace,
                'controller' => 'payoutaccount',
                'action' => 'listBanks',
            ]
        );

        $api->addGet(
            '/stores/{storeId}/payout-accounts',
            [
                'namespace' => $namespace,
                'controller' => 'payoutaccount',
                'action' => 'listAccounts',
            ]
        );

        $api->addPost(
            '/stores/{storeId}/payout-accounts',
            [
                'namespace' => $namespace,
                'controller' => 'payoutaccount',
                'action' => 'registerAccount',
            ]
        );

        $api->addPatch(
            '/stores/{storeId}/payout-accounts/{accountId}/default',
            [
                'namespace' => $namespace,
                'controller' => 'payoutaccount',
                'action' => 'setDefault',
            ]
        );

        $api->addDelete(
            '/stores/{storeId}/payout-accounts/{accountId}',
            [
                'namespace' => $namespace,
                'controller' => 'payoutaccount',
                'action' => 'disableAccount',
            ]
        );
    }
}
