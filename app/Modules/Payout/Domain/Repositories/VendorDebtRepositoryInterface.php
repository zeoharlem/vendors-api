<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Repositories;

interface VendorDebtRepositoryInterface
{
    public function reserveAgainstPayout(
        string $storeId,
        string $payoutId,
        string $currency,
        int $grossPayoutMinor
    ): int;

    public function confirmPayoutRecovery(
        string $storeId,
        string $payoutId,
        string $currency
    ): int;

    public function releasePayoutReservation(
        string $storeId,
        string $payoutId,
        string $currency
    ): int;

    public function outstandingBalance(
        string $storeId,
        string $currency
    ): int;

    /**
     * Restore debt previously recovered against a payout.
     *
     * Used when a payout fails or a successful payout is reversed.
     * Must run inside the caller's active database transaction.
     */
    public function restorePayoutRecovery(
        string $storeId,
        string $payoutId,
        string $currency
    ): int;
}