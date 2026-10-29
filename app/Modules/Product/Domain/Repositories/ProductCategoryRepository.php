<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;

interface ProductCategoryRepository
{
    public function isSelectable(string $categoryId): bool;
}