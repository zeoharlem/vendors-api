<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use App\Modules\Product\Application\Dtos\CreateProductDto;
use App\Modules\Product\Application\Dtos\UpdateProductDto;
use App\Modules\Product\Application\Exceptions\ProductNotFoundException;
use App\Modules\Product\Domain\Entities\Product;
use App\Modules\Product\Domain\Enums\ProductModerationStatus;
use App\Modules\Product\Domain\Enums\ProductStatus;
use App\Modules\Product\Domain\Repositories\ProductRepository;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use App\Modules\Product\Domain\Entities\Inventory;
use App\Modules\Product\Domain\Entities\InventoryMovement;
use App\Modules\Product\Domain\Entities\ProductVariant;
use App\Modules\Product\Domain\Repositories\InventoryMovementRepository;
use App\Modules\Product\Domain\Repositories\InventoryRepository;
use App\Modules\Product\Domain\Repositories\ProductCategoryRepository;
use App\Modules\Product\Domain\Repositories\ProductVariantRepository;

final readonly class ProductService
{
    public function __construct(
        private ProductRepository $productRepository,
        private StoreAccessCheckerInterface $storeAccessChecker,
        private ProductVariantRepository $productVariantRepository,
        private InventoryRepository $inventoryRepository,
        private InventoryMovementRepository $inventoryMovementRepository,
        private ProductCategoryRepository $productCategoryRepository,
        private AdapterInterface $db,
    ) {
    }

    public function create(string $authenticatedUserId, CreateProductDto $dto): Product
    {
        $dto->validate();

        if (trim($authenticatedUserId) === '') {
            throw new DomainException('Authenticated user is required.');
        }

        if (
            !$this->storeAccessChecker->canManageStore($authenticatedUserId, $dto->storeId)
        ) {
            throw new DomainException(
                'You are not authorized to manage this store.'
            );
        }

        if (
            $this->productRepository->findByStoreAndSlug(
                $dto->storeId,
                $dto->slug
            ) !== null
        ) {
            throw new DomainException(
                'A product with this slug already exists in this store.'
            );
        }

        if (
            $dto->categoryId !== null
            && !$this->productCategoryRepository->isSelectable($dto->categoryId)
        ) {
            throw new DomainException(
                'The selected category does not exist, is inactive, or is invalid.'
            );
        }

        $now = new DateTimeImmutable();

        $product = new Product(
            id: $this->generateUuid(),
            storeId: $dto->storeId,
            categoryId: $dto->categoryId,
            name: $dto->name,
            slug: $dto->slug,
            description: $dto->description,
            status: ProductStatus::DRAFT,
            moderationStatus: ProductModerationStatus::PENDING,
            publishedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );

        $variantId = $this->generateUuid();

        $variant = new ProductVariant(
            id: $variantId,
            productId: $product->id,
            sku: $dto->sku,
            name: $dto->variantName,
            attributes: $dto->attributes,
            priceMinor: $dto->priceMinor,
            compareAtMinor: $dto->compareAtMinor,
            currency: $dto->currency,
            status: 'active',
        );

        $inventory = new Inventory(
            variantId: $variantId,
            availableQty: $dto->initialStock,
            reservedQty: 0,
        );

        $movement = new InventoryMovement(
            id: $this->generateUuid(),
            variantId: $variantId,
            movementType: 'initial_stock',
            quantityDelta: $dto->initialStock,
            availableBefore: 0,
            availableAfter: $dto->initialStock,
            reservedBefore: 0,
            reservedAfter: 0,
            reason: 'Initial stock at product creation',
            referenceId: $product->id,
            actorUserId: $authenticatedUserId,
            createdAt: $now,
        );

        $this->db->begin();

        try {
            $createdProduct = $this->productRepository->create($product);

            $this->productVariantRepository->create($variant);

            $this->inventoryRepository->create($inventory);

            $this->inventoryMovementRepository->create($movement);

            $this->db->commit();

            return $createdProduct;
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $rollbackError) {
                // Do not replace the original database/application exception.
                // Log $rollbackError using your configured application logger.
            }

            throw $e;
        }
    }

    public function update(
        string $authenticatedUserId,
        string $productId,
        UpdateProductDto $dto
    ): Product {
        $this->assertNonEmpty($authenticatedUserId, 'Authenticated user ID');
        $this->assertNonEmpty($productId, 'Product ID');

        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new ProductNotFoundException('Product not found.');
        }

        if (
            !$this->storeAccessChecker->canManageStore(
                $authenticatedUserId,
                $product->storeId
            )
        ) {
            throw new DomainException(
                'You are not authorized to update this product.'
            );
        }

        if ($product->status === ProductStatus::ARCHIVED) {
            throw new DomainException(
                'Archived products cannot be updated.'
            );
        }

        $categoryId = $dto->categoryId ?? $product->categoryId;
        $name = $dto->name ?? $product->name;
        $slug = $dto->slug ?? $product->slug;

        $description = $dto->descriptionProvided
            ? $dto->description
            : $product->description;

        $contentChanged =
            $categoryId !== $product->categoryId
            || $name !== $product->name
            || $slug !== $product->slug
            || $description !== $product->description;

        if ($slug !== $product->slug) {
            $existing = $this->productRepository->findByStoreAndSlug(
                $product->storeId,
                $slug
            );

            if ($existing !== null && $existing->id !== $product->id) {
                throw new DomainException(
                    'A product with this slug already exists in this store.'
                );
            }
        }

        /*
         * Any content change to an approved product requires moderation
         * again. Resetting publication prevents changed content from
         * remaining publicly purchasable before review.
         */
        $status = $contentChanged
            ? ProductStatus::DRAFT
            : $product->status;

        $moderationStatus = $contentChanged
            ? ProductModerationStatus::PENDING
            : $product->moderationStatus;

        $publishedAt = $contentChanged
            ? null
            : $product->publishedAt;

        $updated = new Product(
            id: $product->id,
            storeId: $product->storeId,
            categoryId: $categoryId,
            name: $name,
            slug: $slug,
            description: $description,
            status: $status,
            moderationStatus: $moderationStatus,
            publishedAt: $publishedAt,
            createdAt: $product->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        return $this->productRepository->update($updated);
    }

    public function getById(string $productId): Product
    {
        $this->assertNonEmpty($productId, 'Product ID');

        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new ProductNotFoundException('Product not found.');
        }

        return $product;
    }


    public function getManagedById(
        string $authenticatedUserId,
        string $productId
    ): Product {
        $this->assertNonEmpty($authenticatedUserId, 'Authenticated user ID');
        $this->assertNonEmpty($productId, 'Product ID');

        $product = $this->productRepository->findById($productId);

        // Hide both missing products and products belonging to other vendors.
        if ($product === null) {
            throw new ProductNotFoundException('Product not found.');
        }

        if (
            !$this->storeAccessChecker->canManageStore(
                $authenticatedUserId,
                $product->storeId
            )
        ) {
            throw new ProductNotFoundException('Product not found.');
        }

        return $product;
    }


    public function archive(
        string $authenticatedUserId,
        string $productId
    ): Product {
        $this->assertNonEmpty($authenticatedUserId, 'Authenticated user ID');
        $this->assertNonEmpty($productId, 'Product ID');

        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new ProductNotFoundException('Product not found.');
        }

        if (
            !$this->storeAccessChecker->canManageStore(
                $authenticatedUserId,
                $product->storeId
            )
        ) {
            throw new DomainException(
                'You are not authorized to archive this product.'
            );
        }

        if ($product->status === ProductStatus::ARCHIVED) {
            return $product;
        }

        return $this->productRepository->update(
            $product->archive(new DateTimeImmutable())
        );
    }

    private function assertNonEmpty(
        string $value,
        string $fieldName
    ): void {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                $fieldName . ' is required.'
            );
        }
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        // UUID version 4.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);

        // RFC 4122 variant.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

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
