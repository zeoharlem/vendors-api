<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;

use App\Modules\Product\Domain\Entities\InventoryMovement;

interface InventoryMovementRepository
{
    public function create(InventoryMovement $movement): void;
}