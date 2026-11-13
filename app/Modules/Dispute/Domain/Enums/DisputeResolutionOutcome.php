<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Enums;

enum DisputeResolutionOutcome: string
{
    case CUSTOMER_FAVOR = 'customer_favor';
    case VENDOR_FAVOR = 'vendor_favor';
    case PARTIAL_REFUND = 'partial_refund';
    case NO_REFUND = 'no_refund';

    public function requiresRefund(): bool
    {
        return match ($this) {
            self::CUSTOMER_FAVOR,
            self::PARTIAL_REFUND => true,

            self::VENDOR_FAVOR,
            self::NO_REFUND => false,
        };
    }
}
