<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\Dtos\CreateCategoryDto;
use App\Modules\Product\Application\Dtos\UpdateCategoryDto;
use App\Modules\Product\Domain\Entities\Category;
use App\Modules\Product\Domain\Repositories\CategoryRepository;
use DomainException;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $categoryRepository,
    ) {
    }

    public function create(CreateCategoryDto $dto): Category
    {
        $dto->validate();

        if ($this->categoryRepository->slugExists($dto->slug)) {
            throw new DomainException('Category slug already exists.');
        }

        $this->validateParent($dto->parentId);

        $now = new DateTimeImmutable();

        return $this->categoryRepository->create(new Category(
            id: $this->generateUuid(),
            parentId: $dto->parentId,
            name: $dto->name,
            slug: $dto->slug,
            description: $dto->description,
            isActive: $dto->isActive,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public function listAll(): array
    {
        return $this->categoryRepository->findAll();
    }

    public function listActive(): array
    {
        return $this->categoryRepository->findActiveTree();
    }

    public function get(string $id): Category
    {
        return $this->categoryRepository->findById($id)
            ?? throw new DomainException('Category not found.');
    }

    public function update(string $id, UpdateCategoryDto $dto): Category
    {
        $dto->validate();

        $current = $this->get($id);

        $name = $dto->name ?? $current->name;
        $slug = $dto->slug ?? $current->slug;
        $description = $dto->descriptionProvided
            ? $dto->description
            : $current->description;
        $parentId = $dto->parentProvided
            ? $dto->parentId
            : $current->parentId;

        if ($this->categoryRepository->slugExists($slug, $id)) {
            throw new DomainException('Category slug already exists.');
        }

        if ($parentId === $id) {
            throw new DomainException('A category cannot be its own parent.');
        }

        $this->validateParent($parentId);

        if ($parentId !== $current->parentId && $this->categoryRepository->hasChildren($id)) {
            throw new DomainException(
                'A category with subcategories cannot be moved to another level.'
            );
        }

        return $this->categoryRepository->update(new Category(
            id: $current->id,
            parentId: $parentId,
            name: $name,
            slug: $slug,
            description: $description,
            isActive: $current->isActive,
            createdAt: $current->createdAt,
            updatedAt: new DateTimeImmutable(),
        ));
    }

    public function setActive(string $id, bool $isActive): Category
    {
        $current = $this->get($id);

        if (!$isActive && $this->categoryRepository->hasChildren($id)) {
            $children = array_filter(
                $this->categoryRepository->findAll(),
                static fn(Category $category): bool =>
                    $category->parentId === $id && $category->isActive
            );

            if ($children !== []) {
                throw new DomainException(
                    'Deactivate active subcategories before deactivating this category.'
                );
            }
        }

        return $this->categoryRepository->update(new Category(
            id: $current->id,
            parentId: $current->parentId,
            name: $current->name,
            slug: $current->slug,
            description: $current->description,
            isActive: $isActive,
            createdAt: $current->createdAt,
            updatedAt: new DateTimeImmutable(),
        ));
    }

    private function validateParent(?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === '') {
            throw new InvalidArgumentException('Parent ID cannot be empty.');
        }

        $parent = $this->categoryRepository->findById($parentId);

        if ($parent === null || !$parent->isActive) {
            throw new DomainException(
                'Parent category does not exist or is inactive.'
            );
        }

        if ($parent->parentId !== null) {
            throw new DomainException(
                'Only top-level categories can be parents.'
            );
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}