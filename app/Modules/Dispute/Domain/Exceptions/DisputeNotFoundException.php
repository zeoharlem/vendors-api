<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Exceptions;

use RuntimeException;

final class DisputeNotFoundException extends RuntimeException
{
    public static function forId(string $disputeId): self
    {
        return new self("Dispute not found: {$disputeId}");
    }
}
