<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum ProductModerationStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function canBePublished(): bool
    {
        return $this === self::APPROVED;
    }
}
