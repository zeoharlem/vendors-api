<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Entities;

use DateTimeImmutable;

final readonly class ProductMedia
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $objectKey,
        public ?string $altText,
        public int $sortOrder,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
