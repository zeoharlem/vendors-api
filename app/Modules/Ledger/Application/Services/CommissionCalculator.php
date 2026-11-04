<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\Contracts\CommissionCalculatorInterface;
use InvalidArgumentException;

final class CommissionCalculator implements CommissionCalculatorInterface
{
    private const BASIS_POINTS = 10000;

    public function calculate(
        int $commissionBaseMinor,
        int $commissionRateBps
    ): int {
        if ($commissionBaseMinor < 0) {
            throw new InvalidArgumentException(
                'Commission base cannot be negative.'
            );
        }

        if ($commissionRateBps < 0 || $commissionRateBps > self::BASIS_POINTS) {
            throw new InvalidArgumentException(
                'Commission rate must be between 0 and 10000 basis points.'
            );
        }

        if ($commissionBaseMinor === 0 || $commissionRateBps === 0) {
            return 0;
        }

        /*
         * Calculate without multiplying the entire commission base
         * by the rate, reducing the risk of integer overflow.
         */
        $wholeUnits = intdiv($commissionBaseMinor, self::BASIS_POINTS);
        $remainder = $commissionBaseMinor % self::BASIS_POINTS;

        $wholeCommission = $wholeUnits * $commissionRateBps;

        // Round to the nearest minor currency unit.
        $remainderCommission = intdiv(
            ($remainder * $commissionRateBps) + 5000,
            self::BASIS_POINTS
        );

        return $wholeCommission + $remainderCommission;
    }
}