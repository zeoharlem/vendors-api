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
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if (trim($this->storeId) === '') {
            throw new InvalidArgumentException(
                'Store ID is required.'
            );
        }

        if (trim($this->name) === '') {
            throw new InvalidArgumentException(
                'Product name is required.'
            );
        }

        if (mb_strlen(trim($this->name)) > 255) {
            throw new InvalidArgumentException(
                'Product name must not exceed 255 characters.'
            );
        }

        if (trim($this->slug) === '') {
            throw new InvalidArgumentException(
                'Product slug is required.'
            );
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug)) {
            throw new InvalidArgumentException(
                'Product slug must contain lowercase letters, numbers, and single hyphens only.'
            );
        }

        if (
            $this->categoryId !== null
            && trim($this->categoryId) === ''
        ) {
            throw new InvalidArgumentException(
                'Category ID cannot be an empty string.'
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

        if (!is_string($storeId) || !is_string($name) || !is_string($slug)) {
            throw new InvalidArgumentException(
                'Store ID, product name, and slug must be strings.'
            );
        }

        if ($categoryId !== null && !is_string($categoryId)) {
            throw new InvalidArgumentException(
                'Category ID must be a string or null.'
            );
        }

        if ($description !== null && !is_string($description)) {
            throw new InvalidArgumentException(
                'Product description must be a string or null.'
            );
        }

        return new self(
            storeId: trim($storeId),
            categoryId: $categoryId !== null ? trim($categoryId) : null,
            name: trim($name),
            slug: trim($slug),
            description: $description !== null ? trim($description) : null,
        );
    }
}
