<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum ProductStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ARCHIVED = 'archived';

    public function canBeEdited(): bool
    {
        return $this !== self::ARCHIVED;
    }

    public function canBePublished(): bool
    {
        return $this === self::DRAFT || $this === self::PUBLISHED;
    }

    public function canBePurchased(): bool
    {
        return $this === self::PUBLISHED;
    }
}