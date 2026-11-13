<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Enums;

enum DisputeStatus: string
{
    case OPEN = 'open';
    case UNDER_REVIEW = 'under_review';
    case AWAITING_CUSTOMER = 'awaiting_customer';
    case AWAITING_VENDOR = 'awaiting_vendor';

    case RESOLVED_CUSTOMER = 'resolved_customer';
    case RESOLVED_VENDOR = 'resolved_vendor';
    case REJECTED = 'rejected';
    case CLOSED = 'closed';

    public function isActive(): bool
    {
        return match ($this) {
            self::OPEN,
            self::UNDER_REVIEW,
            self::AWAITING_CUSTOMER,
            self::AWAITING_VENDOR => true,

            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return !$this->isActive();
    }

    public function canBeClosed(): bool
    {
        return match ($this) {
            self::RESOLVED_CUSTOMER,
            self::RESOLVED_VENDOR,
            self::REJECTED => true,

            default => false,
        };
    }
}
