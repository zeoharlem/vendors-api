<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Contracts;

interface PayoutLedgerSettlementInterface
{
    /**
     * Post the accounting entries for a completed vendor payout.
     *
     * Must execute inside the caller's database transaction.
     */
    public function postPayout(
        array $payout,
        array $vendorPayable
    ): array;

    public function reversePayout(
        array $payout,
        array $vendorPayable
    ): array;
}