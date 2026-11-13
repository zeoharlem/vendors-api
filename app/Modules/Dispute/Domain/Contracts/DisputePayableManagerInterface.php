<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Contracts;

interface DisputePayableManagerInterface
{
    /**
     * Places the vendor payable on hold and marks the vendor order as disputed.
     *
     * Must participate in the caller's active database transaction.
     *
     * @return string The vendor order status before it was marked disputed.
     */
    public function holdForDispute(
        string $vendorOrderId,
        string $disputeId
    ): string;

    /**
     * Restores the vendor order after a dispute is resolved in the vendor's favour
     * and releases the payable only when no other financial holds exist.
     *
     * Must participate in the caller's active database transaction.
     */
    public function releaseAfterDispute(
        string $vendorOrderId,
        string $disputeId,
        string $previousVendorOrderStatus
    ): void;
}
