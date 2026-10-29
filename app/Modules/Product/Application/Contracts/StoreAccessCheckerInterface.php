<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Contracts;

interface StoreAccessCheckerInterface
{
    public function canManageStore(
        string $userId,
        string $storeId
    ): bool;
}
