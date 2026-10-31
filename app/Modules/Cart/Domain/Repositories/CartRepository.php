<?php

declare(strict_types=1);

namespace App\Modules\Cart\Domain\Repositories;

interface CartRepository
{
    public function getOrCreateActiveCart(
        string $customerId,
        string $currency = 'NGN'
    ): array;

    public function findActiveCart(string $customerId): ?array;

    public function listItems(string $customerId): array;

    public function addItem(
        string $customerId,
        string $variantId,
        int $quantity
    ): array;

    public function updateItemQuantity(
        string $customerId,
        string $itemId,
        int $quantity
    ): array;

    public function removeItem(
        string $customerId,
        string $itemId
    ): bool;
}
