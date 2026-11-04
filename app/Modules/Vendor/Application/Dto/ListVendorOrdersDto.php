<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Dto;

use InvalidArgumentException;

final readonly class ListVendorOrdersDto
{
    public function __construct(
        public string $storeId,
        public ?string $status,
        public int $page,
        public int $perPage
    ) {
    }

    public static function fromArray(array $data): self
    {
        $storeId = trim((string) ($data['store_id'] ?? ''));

        if ($storeId === '') {
            throw new InvalidArgumentException(
                'Store ID is required.'
            );
        }

        $status = isset($data['status'])
            ? trim((string) $data['status'])
            : null;

        return new self(
            storeId: $storeId,
            status: $status === '' ? null : $status,
            page: max(1, (int) ($data['page'] ?? 1)),
            perPage: min(100, max(1, (int) ($data['per_page'] ?? 20)))
        );
    }
}