<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Dtos;

use InvalidArgumentException;

final readonly class InitializePaymentDto
{
    public function __construct(
        public string $orderId
    ) {
        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $this->orderId
            )
        ) {
            throw new InvalidArgumentException('A valid order ID is required.');
        }
    }

    public static function fromArray(array $data): self
    {
        $orderId = $data['order_id'] ?? null;

        if (!is_string($orderId) || trim($orderId) === '') {
            throw new InvalidArgumentException('Order ID is required.');
        }

        return new self(trim($orderId));
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
        ];
    }
}