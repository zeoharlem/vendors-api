<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Dtos;

use DateTimeImmutable;

final readonly class InventoryMovement
{
    public function __construct(
        public string $id,
        public string $variantId,
        public string $movementType,
        public int $quantityDelta,
        public int $availableBefore,
        public int $availableAfter,
        public int $reservedBefore,
        public int $reservedAfter,
        public ?string $reason,
        public ?string $referenceId,
        public ?string $actorUserId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}