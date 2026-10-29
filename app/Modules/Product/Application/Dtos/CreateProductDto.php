<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Dtos;

use InvalidArgumentException;

final readonly class CreateProductDto
{
    public function __construct(
        public string $storeId,
        public ?string $categoryId,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $sku,
        public string $variantName,
        public array $attributes,
        public int $priceMinor,
        public ?int $compareAtMinor,
        public string $currency,
        public int $initialStock,
    ) {
    }

    public function validate(): void
    {
        if (trim($this->storeId) === '') {
            throw new InvalidArgumentException('Store ID is required.');
        }

        if ($this->categoryId !== null && trim($this->categoryId) === '') {
            throw new InvalidArgumentException('Category ID cannot be empty.');
        }

        if (trim($this->name) === '' || mb_strlen($this->name) > 200) {
            throw new InvalidArgumentException(
                'Product name is required and must not exceed 200 characters.'
            );
        }

        if (
            trim($this->slug) === ''
            || mb_strlen($this->slug) > 220
            || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug)
        ) {
            throw new InvalidArgumentException(
                'Slug must contain lowercase letters, numbers, and single hyphens.'
            );
        }

        if (trim($this->sku) === '' || mb_strlen($this->sku) > 100) {
            throw new InvalidArgumentException(
                'SKU is required and must not exceed 100 characters.'
            );
        }

        if (trim($this->variantName) === '' || mb_strlen($this->variantName) > 200) {
            throw new InvalidArgumentException(
                'Variant name is required and must not exceed 200 characters.'
            );
        }

        if ($this->priceMinor < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        if (
            $this->compareAtMinor !== null
            && $this->compareAtMinor < $this->priceMinor
        ) {
            throw new InvalidArgumentException(
                'Compare-at price must be greater than or equal to the selling price.'
            );
        }

        if (!preg_match('/^[A-Z]{3}$/', $this->currency)) {
            throw new InvalidArgumentException(
                'Currency must be a three-letter uppercase currency code.'
            );
        }

        if ($this->initialStock < 0) {
            throw new InvalidArgumentException(
                'Initial stock cannot be negative.'
            );
        }

        if ($this->description !== null && !is_string($this->description)) {
            throw new InvalidArgumentException('Description must be a string or null.');
        }

        if (array_is_list($this->attributes) && $this->attributes !== []) {
            // Lists can be valid JSON, but variant attributes should be an object/map.
            throw new InvalidArgumentException(
                'Variant attributes must be a JSON object.'
            );
        }
    }

    public static function fromArray(array $data): self
    {
        $storeId = $data['store_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;
        $name = $data['name'] ?? null;
        $slug = $data['slug'] ?? null;
        $description = $data['description'] ?? null;
        $variant = $data['initial_variant'] ?? null;
        $initialStock = $data['initial_stock'] ?? 0;

        if (
            !is_string($storeId)
            || !is_string($name)
            || !is_string($slug)
            || ($categoryId !== null && !is_string($categoryId))
            || ($description !== null && !is_string($description))
            || !is_array($variant)
            || !is_int($initialStock)
        ) {
            throw new InvalidArgumentException('Invalid product request payload.');
        }

        $sku = $variant['sku'] ?? null;
        $variantName = $variant['name'] ?? 'Default';
        $attributes = $variant['attributes'] ?? [];
        $priceMinor = $variant['price_minor'] ?? null;
        $compareAtMinor = $variant['compare_at_minor'] ?? null;
        $currency = $variant['currency'] ?? 'NGN';

        if (
            !is_string($sku)
            || !is_string($variantName)
            || !is_array($attributes)
            || !is_int($priceMinor)
            || ($compareAtMinor !== null && !is_int($compareAtMinor))
            || !is_string($currency)
        ) {
            throw new InvalidArgumentException('Invalid initial variant payload.');
        }

        $dto = new self(
            storeId: trim($storeId),
            categoryId: $categoryId === null ? null : trim($categoryId),
            name: trim($name),
            slug: trim($slug),
            description: $description === null ? null : trim($description),
            sku: trim($sku),
            variantName: trim($variantName),
            attributes: $attributes,
            priceMinor: $priceMinor,
            compareAtMinor: $compareAtMinor,
            currency: strtoupper(trim($currency)),
            initialStock: $initialStock,
        );

        $dto->validate();

        return $dto;
    }
}
