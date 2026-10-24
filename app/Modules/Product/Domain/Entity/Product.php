<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entity;

final readonly class Product
{
    public function __construct(
        public string $id,
        public string $vendorId,
        public string $name,
        public string $slug,
        public int $priceMinor,
        public string $currency,
        public bool $isActive,
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Product name cannot be empty.');
        }
        if ($priceMinor < 0) {
            throw new \InvalidArgumentException('Product price cannot be negative.');
        }
    }
}
