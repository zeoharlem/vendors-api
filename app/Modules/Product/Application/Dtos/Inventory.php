<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Dtos;

final readonly class Inventory
{
    public function __construct(
        public string $variantId,
        public int $availableQty,
        public int $reservedQty = 0,
    ) {
    }
}