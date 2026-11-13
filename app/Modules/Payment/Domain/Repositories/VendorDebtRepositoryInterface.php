<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Repositories;

interface VendorDebtRepositoryInterface
{
    /**
     * Record the vendor's outstanding share of a successful refund.
     * Must run inside the caller's active database transaction.
     */
    public function recordRefundDebt(
        string $storeId,
        string $refundId,
        string $vendorOrderId,
        string $currency,
        int $amountMinor
    ): int;

    /**
     * Apply outstanding vendor debt against a payout.
     * Returns the amount deducted from the vendor's payout.
     * Must run inside the caller's active database transaction.
     */
    public function recoverAgainstPayout(
        string $storeId,
        string $payoutId,
        string $currency,
        int $grossPayoutMinor
    ): int;

    public function outstandingBalance(
        string $storeId,
        string $currency
    ): int;
}