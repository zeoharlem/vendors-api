<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Repositories;

interface VendorDebtRepositoryInterface
{
    public function recordRefundDebt(
        string $storeId,
        string $refundId,
        string $vendorOrderId,
        string $currency,
        int $amountMinor
    ): int;

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
}