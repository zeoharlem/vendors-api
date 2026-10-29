<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Entities;

use App\Modules\Product\Domain\Enums\ProductModerationStatus;
use App\Modules\Product\Domain\Enums\ProductStatus;
use DateTimeImmutable;
use DomainException;

final readonly class Product
{
    public function __construct(
        public string $id,
        public string $storeId,
        public ?string $categoryId,
        public string $name,
        public string $slug,
        public ?string $description,
        public ProductStatus $status,
        public ProductModerationStatus $moderationStatus,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function canBePublished(): bool
    {
        return $this->status->canBePublished() && $this->moderationStatus->canBePublished();
    }

    public function canBePurchased(): bool
    {
        return $this->status->canBePurchased() && $this->moderationStatus->canBePublished();
    }

    public function publish(DateTimeImmutable $publishedAt): self
    {
        if (!$this->canBePublished()) {
            throw new DomainException('This product cannot be published until it is approved.');
        }

        return new self(
            id: $this->id,
            storeId: $this->storeId,
            categoryId: $this->categoryId,
            name: $this->name,
            slug: $this->slug,
            description: $this->description,
            status: ProductStatus::PUBLISHED,
            moderationStatus: $this->moderationStatus,
            publishedAt: $publishedAt,
            createdAt: $this->createdAt,
            updatedAt: $publishedAt,
        );
    }

    public function archive(DateTimeImmutable $updatedAt): self
    {
        return new self(
            id: $this->id,
            storeId: $this->storeId,
            categoryId: $this->categoryId,
            name: $this->name,
            slug: $this->slug,
            description: $this->description,
            status: ProductStatus::ARCHIVED,
            moderationStatus: $this->moderationStatus,
            publishedAt: $this->publishedAt,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
        );
    }
}
