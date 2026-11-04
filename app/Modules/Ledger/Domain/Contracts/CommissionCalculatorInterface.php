<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Contracts;

interface CommissionCalculatorInterface
{
    /**
     * Calculate the platform commission in minor currency units.
     *
     * @param int $commissionBaseMinor Merchandise subtotal after discounts.
     * @param int $commissionRateBps Commission rate in basis points.
     */
    public function calculate(
        int $commissionBaseMinor,
        int $commissionRateBps
    ): int;
}