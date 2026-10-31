<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Repositories;

interface OrderRepository
{
    /**
     * Creates a parent order and its vendor orders from the customer's cart.
     *
     * This operation must be atomic.
     *
     * @return array The created order and its vendor orders.
     */
    public function checkout(
        string $customerId,
        array $checkoutData,
        string $idempotencyKey
    ): array;

    /**
     * Retrieves an order belonging to a specific customer.
     */
    public function findCustomerOrder(
        string $customerId,
        string $orderId
    ): ?array;
}