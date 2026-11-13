<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Exceptions;

use RuntimeException;

final class DisputeAlreadyExistsException extends RuntimeException
{
    public static function forVendorOrder(string $vendorOrderId): self
    {
        return new self(
            "An active dispute already exists for vendor order: {$vendorOrderId}"
        );
    }
}
