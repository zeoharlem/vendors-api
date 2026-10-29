<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;

use App\Modules\Product\Domain\Entities\Category;

interface CategoryRepository
{
    public function create(Category $category): Category;

    public function update(Category $category): Category;

    public function findById(string $id): ?Category;

    /** @return list<Category> */
    public function findAll(): array;

    /** @return list<Category> */
    public function findActiveTree(): array;

    public function slugExists(string $slug, ?string $excludeId = null): bool;

    public function hasChildren(string $categoryId): bool;

    public function hasProducts(string $categoryId): bool;
}