<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\ValueObjects;

final readonly class DisputableVendorOrder
{
    public function __construct(
        public string $vendorOrderId,
        public string $orderId,
        public ?string $vendorPayableId,
    ) {
        if (
            $this->vendorOrderId === ''
            || $this->orderId === ''
        ) {
            throw new \InvalidArgumentException(
                'Vendor order ID and parent order ID are required.'
            );
        }
    }
}
