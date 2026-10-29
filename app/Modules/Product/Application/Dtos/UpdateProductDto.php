<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Dtos;

use InvalidArgumentException;

final readonly class UpdateProductDto
{
    public function __construct(
        public ?string $categoryId = null,
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $description = null,
        public bool $descriptionProvided = false,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->name !== null) {
            if (trim($this->name) === '') {
                throw new InvalidArgumentException(
                    'Product name cannot be empty.'
                );
            }

            if (mb_strlen(trim($this->name)) > 255) {
                throw new InvalidArgumentException(
                    'Product name must not exceed 255 characters.'
                );
            }
        }

        if ($this->slug !== null) {
            if (
                !preg_match(
                    '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                    $this->slug
                )
            ) {
                throw new InvalidArgumentException(
                    'Product slug must contain lowercase letters, numbers, and single hyphens only.'
                );
            }
        }

        if (
            $this->categoryId !== null
            && trim($this->categoryId) === ''
        ) {
            throw new InvalidArgumentException(
                'Category ID cannot be an empty string.'
            );
        }

        if (
            $this->descriptionProvided
            && $this->description !== null
            && !is_string($this->description)
        ) {
            throw new InvalidArgumentException(
                'Product description must be a string or null.'
            );
        }
    }

    public static function fromArray(array $data): self
    {
        $categoryId = $data['category_id'] ?? null;
        $name = $data['name'] ?? null;
        $slug = $data['slug'] ?? null;
        $descriptionProvided = array_key_exists('description', $data);
        $description = $data['description'] ?? null;

        if ($categoryId !== null && !is_string($categoryId)) {
            throw new InvalidArgumentException(
                'Category ID must be a string or null.'
            );
        }

        if ($name !== null && !is_string($name)) {
            throw new InvalidArgumentException(
                'Product name must be a string.'
            );
        }

        if ($slug !== null && !is_string($slug)) {
            throw new InvalidArgumentException(
                'Product slug must be a string.'
            );
        }

        if ($descriptionProvided && $description !== null && !is_string($description)) {
            throw new InvalidArgumentException(
                'Product description must be a string or null.'
            );
        }

        if (
            $categoryId === null
            && $name === null
            && $slug === null
            && !$descriptionProvided
        ) {
            throw new InvalidArgumentException(
                'At least one product field must be provided for an update.'
            );
        }

        return new self(
            categoryId: $categoryId !== null ? trim($categoryId) : null,
            name: $name !== null ? trim($name) : null,
            slug: $slug !== null ? trim($slug) : null,
            description: $description,
            descriptionProvided: $descriptionProvided,
        );
    }
}
