<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application\Services;

use App\Modules\Cart\Application\Dtos\AddCartItemDto;
use App\Modules\Cart\Application\Dtos\UpdateCartItemDto;
use App\Modules\Cart\Domain\Repositories\CartRepository;
use DomainException;
use InvalidArgumentException;

final readonly class CartService
{
    public function __construct(
        private CartRepository $cartRepository
    ) {
    }

    public function getCart(string $customerId): array
    {
        $this->assertCustomerId($customerId);

        $cart = $this->cartRepository->findActiveCart($customerId);

        if ($cart === null) {
            $cart = $this->cartRepository->getOrCreateActiveCart(
                $customerId
            );
        }

        $items = $this->cartRepository->listItems($customerId);

        $subtotal = 0;

        foreach ($items as &$item) {
            $item['quantity'] = (int) $item['quantity'];
            $item['price_minor'] = (int) $item['price_minor'];
            $item['line_total_minor'] =
                $item['price_minor'] * $item['quantity'];

            $item['available_qty'] = (int) ($item['available_qty'] ?? 0);

            $item['is_available'] =
                $item['variant_status'] === 'active'
                && $item['product_status'] === 'published'
                && $item['moderation_status'] === 'approved'
                && $item['store_status'] === 'active'
                && $item['verification_status'] === 'verified'
                && $item['available_qty'] >= $item['quantity'];

            if ($item['is_available']) {
                $subtotal += $item['line_total_minor'];
            }
        }

        unset($item);

        return [
            'id' => $cart['id'],
            'currency' => $cart['currency'],
            'status' => $cart['status'],
            'items' => $items,
            'item_count' => count($items),
            'subtotal_minor' => $subtotal,
        ];
    }

    public function addItem(
        string $customerId,
        AddCartItemDto $dto
    ): array {
        $this->assertCustomerId($customerId);

        $this->cartRepository->addItem(
            $customerId,
            $dto->variantId,
            $dto->quantity
        );

        return $this->getCart($customerId);
    }

    public function updateItem(
        string $customerId,
        string $itemId,
        UpdateCartItemDto $dto
    ): array {
        $this->assertCustomerId($customerId);
        $this->assertUuid($itemId, 'cart item ID');

        $this->cartRepository->updateItemQuantity(
            $customerId,
            $itemId,
            $dto->quantity
        );

        return $this->getCart($customerId);
    }

    public function removeItem(
        string $customerId,
        string $itemId
    ): array {
        $this->assertCustomerId($customerId);
        $this->assertUuid($itemId, 'cart item ID');

        if (!$this->cartRepository->removeItem($customerId, $itemId)) {
            throw new DomainException('Cart item not found.');
        }

        return $this->getCart($customerId);
    }

    private function assertCustomerId(string $customerId): void
    {
        $this->assertUuid($customerId, 'customer ID');
    }

    private function assertUuid(string $value, string $field): void
    {
        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            )
        ) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
    }
}
