<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;

use App\Modules\Product\Domain\Entities\Inventory;

interface InventoryRepository
{
    public function create(Inventory $inventory): void;
}