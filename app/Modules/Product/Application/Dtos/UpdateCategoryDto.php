<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Dtos;

use InvalidArgumentException;

final readonly class UpdateCategoryDto
{
    public function __construct(
        public ?string $name,
        public ?string $slug,
        public ?string $description,
        public bool $descriptionProvided,
        public ?string $parentId,
        public bool $parentProvided,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $slug = $data['slug'] ?? null;
        $descriptionProvided = array_key_exists('description', $data);
        $description = $data['description'] ?? null;
        $parentProvided = array_key_exists('parent_id', $data);
        $parentId = $data['parent_id'] ?? null;

        if (
            ($name !== null && !is_string($name))
            || ($slug !== null && !is_string($slug))
            || ($descriptionProvided && $description !== null && !is_string($description))
            || ($parentProvided && $parentId !== null && !is_string($parentId))
        ) {
            throw new InvalidArgumentException('Invalid category update payload.');
        }

        $dto = new self(
            name: $name === null ? null : trim($name),
            slug: $slug === null ? null : trim($slug),
            description: $description === null ? null : trim($description),
            descriptionProvided: $descriptionProvided,
            parentId: $parentId === null ? null : trim($parentId),
            parentProvided: $parentProvided,
        );

        $dto->validate();

        return $dto;
    }

    public function validate(): void
    {
        if (
            $this->name !== null && (
                $this->name === '' || mb_strlen($this->name) > 150
            )
        ) {
            throw new InvalidArgumentException('Invalid category name.');
        }

        if (
            $this->slug !== null && (
                $this->slug === ''
                || mb_strlen($this->slug) > 180
                || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->slug)
            )
        ) {
            throw new InvalidArgumentException('Invalid category slug.');
        }

        if ($this->parentProvided && $this->parentId === '') {
            throw new InvalidArgumentException(
                'Use null to move a category to the top level.'
            );
        }
    }
}