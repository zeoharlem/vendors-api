<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Services;

use App\Modules\Store\Application\Dtos\CreateStoreRequestDTO;
use App\Modules\Store\Application\Dtos\StoreResponseDto;
use App\Modules\Store\Domain\Repository\StoreRepository;
use App\Modules\Vendor\Domain\Repository\VendorRepository;

final class StoreService
{
    public function __construct(
        private readonly StoreRepository $storeRepository,
        private readonly VendorRepository $vendorRepository,
    ) {
    }

    public function createForUser(string $userId, CreateStoreRequestDTO $request): StoreResponseDto
    {
        $vendorProfile = $this->vendorRepository
            ->findByUserId($userId);

        if ($vendorProfile === null) {
            throw new \DomainException(
                'Create a vendor profile before creating a store.'
            );
        }

        $baseSlug = $this->slugify($request->name);

        if ($baseSlug === '') {
            throw new \InvalidArgumentException(
                'Store name must contain letters or numbers.'
            );
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->storeRepository->slugExists($slug)) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $store = $this->storeRepository->createForVendor(
            (string) $vendorProfile['id'],
            $request->name,
            $slug,
            $request->description,
        );

        return StoreResponseDto::fromArray($store);
    }

    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value)));
        return trim($slug ?? '', '-');
    }

    public function listPublic(int $limit, int $offset): array
    {
        $stores = $this->storeRepository->listPublic($limit, $offset);

        return array_map(
            static fn(array $store): StoreResponseDto => StoreResponseDto::fromArray($store),
            $stores,
        );
    }

    public function getPublicById(string $id): ?StoreResponseDto
    {
        $store = $this->storeRepository->findPublicById($id);
        return $store === null ? null : StoreResponseDto::fromArray($store);
    }

    public function listForUser(string $userId): array
    {
        $vendorProfile = $this->vendorRepository->findByUserId($userId);

        if ($vendorProfile === null) {
            throw new \DomainException('Create a vendor profile before viewing your stores.');
        }

        $stores = $this->storeRepository->findByVendorProfileId((string) $vendorProfile['id']);

        return array_map(
            static fn(array $store): StoreResponseDto => StoreResponseDto::fromArray($store),
            $stores,
        );
    }
}
