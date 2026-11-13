<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Contracts;

interface DisputeRefundManagerInterface
{
    /**
     * Requests a refund through the existing Payment module.
     *
     * Returns the created refund ID.
     */
    public function requestRefund(
        string $disputeId,
        string $vendorOrderId,
        string $requestedBy,
        ?int $amountMinor,
        string $reason,
        string $idempotencyKey,
    ): string;
}
