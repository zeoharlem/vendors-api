<?php

declare(strict_types=1);

namespace App\Modules\Store\Application\Dtos;

final readonly class StoreResponseDto
{
    public function __construct(
        public string $id,
        public string $vendorProfileId,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $status,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    public static function fromArray(array $store): self
    {
        return new self(
            id: (string) $store['id'],
            vendorProfileId: (string) $store['vendor_profile_id'],
            name: (string) $store['name'],
            slug: (string) $store['slug'],
            description: isset($store['description']) ? (string) $store['description'] : null,
            status: (string) $store['status'],
            createdAt: isset($store['created_at']) ? (string) $store['created_at'] : null,
            updatedAt: isset($store['updated_at']) ? (string) $store['updated_at'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vendor_profile_id' => $this->vendorProfileId,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
