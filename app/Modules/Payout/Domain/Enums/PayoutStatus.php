<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Enums;

enum PayoutStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCESS = 'success';
    case FAILED = 'failed';
    case REVERSED = 'reversed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCESS,
            self::FAILED,
            self::REVERSED,
            self::CANCELLED => true,

            self::PENDING,
            self::PROCESSING => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PENDING => in_array(
                $next,
                [self::PROCESSING, self::SUCCESS, self::FAILED, self::CANCELLED],
                true
            ),

            self::PROCESSING => in_array(
                $next,
                [self::SUCCESS, self::FAILED, self::REVERSED],
                true
            ),

            self::SUCCESS => $next === self::REVERSED,

            self::FAILED,
            self::REVERSED,
            self::CANCELLED => false,
        };
    }
}