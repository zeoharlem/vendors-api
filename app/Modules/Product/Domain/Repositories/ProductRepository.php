<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;


use App\Modules\Product\Domain\Entities\Product;

interface ProductRepository
{
    public function create(Product $product): Product;

    public function update(Product $product): Product;

    public function findById(string $id): ?Product;

    public function findPublishedById(string $id): ?Product;

    public function findByStoreAndSlug(string $storeId, string $slug): ?Product;
}
