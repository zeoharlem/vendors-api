<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Services;

use App\Modules\Payout\Domain\Exceptions\PayoutAccountAccessDeniedException;
use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use App\Modules\Vendor\Domain\Repository\VendorOrderRepository;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;
use DateTimeZone;

final class VendorOrderService
{
    private const ALLOWED_FILTER_STATUSES = [
        'pending',
        'accepted',
        'processing',
        'ready_to_ship',
        'shipped',
        'delivered',
        'completed',
        'cancelled',
        'disputed',
        'partially_refunded',
        'refunded',
    ];

    private const TRANSITIONS = [
        'pending' => 'accepted',
        'accepted' => 'processing',
        'processing' => 'ready_to_ship',
        'ready_to_ship' => 'shipped',
        'shipped' => 'delivered',
    ];

    public function __construct(
        private readonly VendorOrderRepository $vendorOrderRepository,
        private readonly StoreAccessCheckerInterface $storeAccessChecker,
        private readonly int $acceptanceWindowHours = 72
    ) {
        if ($this->acceptanceWindowHours < 1) {
            throw new InvalidArgumentException(
                'Acceptance window must be at least one hour.'
            );
        }
    }

    public function list(
        string $authenticatedUserId,
        string $storeId,
        ?string $status = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $this->assertStoreAccess($authenticatedUserId, $storeId);

        if (
            $status !== null &&
            !in_array($status, self::ALLOWED_FILTER_STATUSES, true)
        ) {
            throw new InvalidArgumentException(
                'Invalid vendor order status filter.'
            );
        }

        if ($page < 1) {
            throw new InvalidArgumentException(
                'Page must be greater than zero.'
            );
        }

        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException(
                'Per page must be between 1 and 100.'
            );
        }

        $offset = ($page - 1) * $perPage;

        $items = $this->vendorOrderRepository->listByStore(
            $storeId,
            $status,
            $perPage,
            $offset
        );

        $total = $this->vendorOrderRepository->countByStore(
            $storeId,
            $status
        );

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function get(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId
    ): array {
        $this->assertStoreAccess($authenticatedUserId, $storeId);

        $vendorOrder = $this->vendorOrderRepository->findByIdForStore(
            $vendorOrderId,
            $storeId
        );

        if ($vendorOrder === null) {
            throw new RuntimeException(
                'Vendor order not found.'
            );
        }

        return $vendorOrder;
    }

    public function accept(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId
    ): array {
        return $this->transition(
            $authenticatedUserId,
            $storeId,
            $vendorOrderId,
            'accepted'
        );
    }

    public function startProcessing(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId
    ): array {
        return $this->transition(
            $authenticatedUserId,
            $storeId,
            $vendorOrderId,
            'processing'
        );
    }

    public function markReadyToShip(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId
    ): array {
        return $this->transition(
            $authenticatedUserId,
            $storeId,
            $vendorOrderId,
            'ready_to_ship'
        );
    }

    public function ship(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId,
        string $trackingNumber,
        ?string $trackingUrl = null
    ): array {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw new InvalidArgumentException(
                'Tracking number is required.'
            );
        }

        if (strlen($trackingNumber) > 150) {
            throw new InvalidArgumentException(
                'Tracking number cannot exceed 150 characters.'
            );
        }

        if ($trackingUrl !== null) {
            $trackingUrl = trim($trackingUrl);

            if (
                $trackingUrl === '' ||
                strlen($trackingUrl) > 2048 ||
                filter_var($trackingUrl, FILTER_VALIDATE_URL) === false
            ) {
                throw new InvalidArgumentException(
                    'A valid tracking URL is required.'
                );
            }

            $scheme = strtolower(
                (string) parse_url($trackingUrl, PHP_URL_SCHEME)
            );

            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException(
                    'Tracking URL must use HTTP or HTTPS.'
                );
            }
        }

        return $this->transition(
            $authenticatedUserId,
            $storeId,
            $vendorOrderId,
            'shipped',
            [
                'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingUrl,
            ]
        );
    }

    public function markDelivered(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId
    ): array {
        $deadline = (new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        ))
            ->modify(sprintf('+%d hours', $this->acceptanceWindowHours))
            ->format('Y-m-d H:i:sP');

        return $this->transition(
            $authenticatedUserId,
            $storeId,
            $vendorOrderId,
            'delivered',
            [
                'acceptance_deadline' => $deadline,
            ]
        );
    }

    public function confirmDelivery(
        string $authenticatedUserId,
        string $vendorOrderId
    ): array {
        if (!$this->isValidUuid($authenticatedUserId)) {
            throw new InvalidArgumentException(
                'Invalid authenticated customer ID.'
            );
        }

        if (!$this->isValidUuid($vendorOrderId)) {
            throw new InvalidArgumentException(
                'Invalid vendor order ID.'
            );
        }

        $vendorOrder = $this->vendorOrderRepository
            ->confirmCustomerDelivery(
                $authenticatedUserId,
                $vendorOrderId
            );

        if ($vendorOrder === null) {
            throw new RuntimeException(
                'Vendor order was not found for this customer.'
            );
        }

        return $vendorOrder;
    }

    private function transition(
        string $authenticatedUserId,
        string $storeId,
        string $vendorOrderId,
        string $newStatus,
        array $additionalData = []
    ): array {
        $this->assertStoreAccess($authenticatedUserId, $storeId);

        $vendorOrder = $this->vendorOrderRepository->findByIdForStore(
            $vendorOrderId,
            $storeId
        );

        if ($vendorOrder === null) {
            throw new RuntimeException(
                'Vendor order not found.'
            );
        }

        $currentStatus = $vendorOrder['status'];

        $expectedNextStatus = self::TRANSITIONS[$currentStatus] ?? null;

        if ($expectedNextStatus !== $newStatus) {
            throw new RuntimeException(
                sprintf(
                    'Cannot transition vendor order from %s to %s.',
                    $currentStatus,
                    $newStatus
                )
            );
        }

        $updatedOrder = $this->vendorOrderRepository->updateStatus(
            $vendorOrderId,
            $storeId,
            $currentStatus,
            $newStatus,
            $additionalData
        );

        if ($updatedOrder === null) {
            throw new RuntimeException(
                'Vendor order status changed. Please refresh and try again.'
            );
        }

        return $updatedOrder;
    }

    private function assertStoreAccess(
        string $authenticatedUserId,
        string $storeId
    ): void {
        if (
            !$this->storeAccessChecker->canManageStore(
                $authenticatedUserId,
                $storeId
            )
        ) {
            throw new PayoutAccountAccessDeniedException(
                'You are not authorized to manage payout accounts for this store.'
            );
        }
    }

    private function isValidUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
