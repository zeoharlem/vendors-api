<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Contracts;

use App\Modules\Dispute\Domain\ValueObjects\DisputableVendorOrder;

interface DisputeVendorOrderAccessInterface
{
    /**
     * Returns vendor-order details after verifying customer ownership.
     *
     * @throws \App\Modules\Dispute\Domain\Exceptions\DisputeAccessDeniedException
     */
    public function getForCustomer(
        string $vendorOrderId,
        string $customerId
    ): DisputableVendorOrder;
}
