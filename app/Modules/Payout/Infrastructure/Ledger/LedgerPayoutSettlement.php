<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Ledger;

use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Payout\Domain\Contracts\PayoutLedgerSettlementInterface;

final readonly class LedgerPayoutSettlement implements PayoutLedgerSettlementInterface
{
    public function __construct(
        private LedgerPostingService $ledgerPostingService
    ) {
    }

    public function postPayout(
        array $payout,
        array $vendorPayable
    ): array {
        return $this->ledgerPostingService->postVendorPayout(
            $payout,
            $vendorPayable
        );
    }

    public function reversePayout(
        array $payout,
        array $vendorPayable
    ): array {
        return $this->ledgerPostingService->reverseVendorPayout(
            $payout,
            $vendorPayable
        );
    }
}