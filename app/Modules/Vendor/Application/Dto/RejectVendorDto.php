<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Dto;

final readonly class RejectVendorDto
{
    public function __construct(
        public string $reason,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reason: is_string($data['reason'] ?? null) ? trim($data['reason']) : '',
        );
    }
}