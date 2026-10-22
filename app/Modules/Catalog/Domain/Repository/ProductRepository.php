<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Repository;

use App\Modules\Catalog\Domain\Entity\Product;

interface ProductRepository
{
    /** @return list<Product> */
    public function findPublished(int $limit, int $offset): array;

    public function findById(string $id): ?Product;
}
