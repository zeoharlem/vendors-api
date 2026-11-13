<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Repositories;


use App\Modules\Product\Domain\Entities\ProductMedia;

interface ProductMediaRepository
{
    public function create(ProductMedia $media): ProductMedia;

    /**
     * @return ProductMedia[]
     */
    public function findByProductId(string $productId): array;

    public function findById(
        string $productId,
        string $mediaId
    ): ?ProductMedia;

    public function updateDetails(
        string $productId,
        string $mediaId,
        ?string $altText,
        int $sortOrder
    ): ?ProductMedia;

    public function delete(
        string $productId,
        string $mediaId
    ): bool;

    public function countByProductId(string $productId): int;

    public function replaceObjectKey(
        string $productId,
        string $mediaId,
        string $objectKey,
    ): ?ProductMedia;
}
