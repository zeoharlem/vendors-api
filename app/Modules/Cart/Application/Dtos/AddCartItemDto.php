<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Dtos;

use InvalidArgumentException;

final readonly class AddCartItemDto
{
    public function __construct(
        public string $variantId,
        public int $quantity
    ) {
        if (!self::isUuid($variantId)) {
            throw new InvalidArgumentException('A valid variant_id is required.');
        }

        if ($quantity < 1 || $quantity > 1000) {
            throw new InvalidArgumentException(
                'Quantity must be between 1 and 1000.'
            );
        }
    }

    public static function fromArray(array $data): self
    {
        $variantId = $data['variant_id'] ?? null;
        $quantity = $data['quantity'] ?? null;

        if (!is_string($variantId)) {
            throw new InvalidArgumentException('variant_id is required.');
        }

        if (!is_int($quantity)) {
            throw new InvalidArgumentException(
                'quantity must be an integer.'
            );
        }

        return new self($variantId, $quantity);
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
