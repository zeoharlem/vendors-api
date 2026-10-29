<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Entities;

use DateTimeImmutable;

final readonly class Category
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $name,
        public string $slug,
        public ?string $description,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}