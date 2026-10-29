<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;

use App\Modules\Product\Domain\Entities\ProductVariant;

interface ProductVariantRepository
{
    public function create(ProductVariant $variant): void;
}