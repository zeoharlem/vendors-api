<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Repositories;

interface VendorPayableRepository
{
    public function createHeldIfNotExists(array $payableData): array;
}