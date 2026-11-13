<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Entities;

final readonly class ProductVariant
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $sku,
        public string $name,
        public array $attributes,
        public int $priceMinor,
        public ?int $compareAtMinor,
        public string $currency,
        public string $status = 'active',
    ) {
    }
}