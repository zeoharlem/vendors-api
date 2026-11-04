<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Dto;

use InvalidArgumentException;

final readonly class ShipVendorOrderDto
{
    public function __construct(
        public string $storeId,
        public string $trackingNumber,
        public ?string $trackingUrl
    ) {
    }

    public static function fromArray(array $data): self
    {
        $storeId = trim((string) ($data['store_id'] ?? ''));
        $trackingNumber = trim(
            (string) ($data['tracking_number'] ?? '')
        );

        $trackingUrl = isset($data['tracking_url'])
            ? trim((string) $data['tracking_url'])
            : null;

        if ($storeId === '') {
            throw new InvalidArgumentException(
                'Store ID is required.'
            );
        }

        if ($trackingNumber === '') {
            throw new InvalidArgumentException(
                'Tracking number is required.'
            );
        }

        if (strlen($trackingNumber) > 150) {
            throw new InvalidArgumentException(
                'Tracking number cannot exceed 150 characters.'
            );
        }

        if ($trackingUrl === '') {
            $trackingUrl = null;
        }

        return new self(
            storeId: $storeId,
            trackingNumber: $trackingNumber,
            trackingUrl: $trackingUrl
        );
    }
}