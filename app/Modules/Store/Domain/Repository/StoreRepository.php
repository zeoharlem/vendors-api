<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Repository;

interface StoreRepository
{
    public function createForVendor(
        string $vendorProfileId,
        string $name,
        string $slug,
        ?string $description,
    ): array;

    public function findBySlug(string $slug): ?array;

    public function slugExists(string $slug): bool;

    public function findPublicById(string $id): ?array;

    public function listPublic(int $limit, int $offset): array;

    public function findByVendorProfileId(
        string $vendorProfileId,
    ): array;
}
