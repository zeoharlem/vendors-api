<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Exceptions;

use RuntimeException;

final class VendorProfileAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'A vendor profile already exists for this user.'
        );
    }
}