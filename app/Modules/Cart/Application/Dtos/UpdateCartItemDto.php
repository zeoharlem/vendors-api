<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Dtos;

use InvalidArgumentException;

final readonly class UpdateCartItemDto
{
    public function __construct(
        public int $quantity
    ) {
        if ($quantity < 1 || $quantity > 1000) {
            throw new InvalidArgumentException(
                'Quantity must be between 1 and 1000.'
            );
        }
    }

    public static function fromArray(array $data): self
    {
        $quantity = $data['quantity'] ?? null;

        if (!is_int($quantity)) {
            throw new InvalidArgumentException(
                'quantity must be an integer.'
            );
        }

        return new self($quantity);
    }
}
