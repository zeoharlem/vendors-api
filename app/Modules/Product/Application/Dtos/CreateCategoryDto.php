<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Dtos;

use InvalidArgumentException;

final readonly class CreateCategoryDto
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $parentId,
        public bool $isActive = true,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $slug = $data['slug'] ?? null;
        $description = $data['description'] ?? null;
        $parentId = $data['parent_id'] ?? null;
        $isActive = $data['is_active'] ?? true;

        if (
            !is_string($name)
            || !is_string($slug)
            || ($description !== null && !is_string($description))
            || ($parentId !== null && !is_string($parentId))
            || !is_bool($isActive)
        ) {
            throw new InvalidArgumentException('Invalid category payload.');
        }

        $dto = new self(
            name: trim($name),
            slug: trim($slug),
            description: $description === null ? null : trim($description),
            parentId: $parentId === null ? null : trim($parentId),
            isActive: $isActive,
        );

        $dto->validate();

        return $dto;
    }

    public function validate(): void
    {
        if ($this->name === '' || mb_strlen($this->name) > 150) {
            throw new InvalidArgumentException(
                'Category name is required and must not exceed 150 characters.'
            );
        }

        if (
            $this->slug === ''
            || mb_strlen($this->slug) > 180
            || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug)
        ) {
            throw new InvalidArgumentException('Invalid category slug.');
        }

        if ($this->parentId !== null && $this->parentId === '') {
            throw new InvalidArgumentException('Parent ID cannot be empty.');
        }
    }
}