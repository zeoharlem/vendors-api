<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\ValueObjects;

final readonly class DisputeOrderContext
{
    public function __construct(
        public string $orderId,
        public string $vendorOrderId,
        public ?string $vendorPayableId,
        public string $storeId,
        public string $customerId,
        public string $vendorOrderStatus,
    ) {
        foreach ([
            'orderId' => $orderId,
            'vendorOrderId' => $vendorOrderId,
            'storeId' => $storeId,
            'customerId' => $customerId,
            'vendorOrderStatus' => $vendorOrderStatus,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(
                    sprintf('%s cannot be empty.', $field)
                );
            }
        }
    }
}
