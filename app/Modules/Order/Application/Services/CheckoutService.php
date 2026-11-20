<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Dtos\CheckoutDto;
use App\Modules\Order\Domain\Repositories\OrderRepository;
use InvalidArgumentException;

final readonly class CheckoutService
{
    public function __construct(
        private OrderRepository $orderRepository
    ) {
    }

    public function checkout(
        string $customerId,
        CheckoutDto $dto,
        string $idempotencyKey
    ): array {
        if ($customerId === '') {
            throw new InvalidArgumentException(
                'Authenticated customer is required.'
            );
        }

        if (
            $idempotencyKey === '' ||
            strlen($idempotencyKey) > 100
        ) {
            throw new InvalidArgumentException(
                'A valid Idempotency-Key header is required.'
            );
        }

        return $this->orderRepository->checkout(
            $customerId,
            $dto->toArray(),
            $idempotencyKey
        );
    }

    public function getOrder(
        string $customerId,
        string $orderId
    ): ?array {
        return $this->orderRepository->findCustomerOrder(
            $customerId,
            $orderId
        );
    }

    public function cancelAdminOrder(
        string $orderId,
        string $adminId,
        string $reason
    ): array {
        $orderId = trim($orderId);
        $adminId = trim($adminId);
        $reason = trim($reason);

        if ($orderId === '') {
            throw new InvalidArgumentException('A valid order ID is required.');
        }

        if ($adminId === '') {
            throw new InvalidArgumentException('An authenticated administrator is required.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A cancellation reason is required.');
        }

        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException(
                'Cancellation reason cannot exceed 1000 characters.'
            );
        }

        return $this->orderRepository->cancelAdminOrder(
            $orderId,
            $adminId,
            $reason
        );
    }

    /**
     * Lists parent orders for authorised administrative users.
     */
    public function listAdminOrders(array $filters): array
    {
        return $this->orderRepository->listAdminOrders($filters);
    }

    public function getAdminOrder(string $orderId): ?array
    {
        return $this->orderRepository->findAdminOrder($orderId);
    }
}