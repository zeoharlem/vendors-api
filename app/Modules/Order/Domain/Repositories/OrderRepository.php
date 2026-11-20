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

    /**
     * Retrieves a parent order and its associated vendor orders
     * for administrative use.
     */
    public function findAdminOrder(string $orderId): ?array;

    /**
     * Cancels an order administratively.
     *
     * The operation must atomically update order statuses, release eligible
     * inventory reservations, and record the status history.
     *
     * @return array The updated order.
     */
    public function cancelAdminOrder(
        string $orderId,
        string $adminId,
        string $reason
    ): array;

    /**
     * Returns a paginated list of all parent orders for administrative use.
     *
     * @return array{
     *     items: array,
     *     pagination: array{
     *         page: int,
     *         per_page: int,
     *         total: int,
     *         total_pages: int
     *     }
     * }
     */
    public function listAdminOrders(array $filters): array;
}