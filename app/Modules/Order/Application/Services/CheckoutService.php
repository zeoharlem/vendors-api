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
}