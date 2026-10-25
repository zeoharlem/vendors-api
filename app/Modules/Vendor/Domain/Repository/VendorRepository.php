<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Domain\Repository;

interface VendorRepository
{
    public function findByUserId(string $userId): ?array;

    public function createForUser(
        string $userId,
        string $businessName,
        ?string $businessRegistrationNumber,
    ): array;
}