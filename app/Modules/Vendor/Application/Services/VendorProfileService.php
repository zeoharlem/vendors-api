<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Services;

use App\Modules\Vendor\Application\Exceptions\VendorProfileAlreadyExistsException;
use App\Modules\Vendor\Domain\Repository\VendorRepository;
use InvalidArgumentException;

final class VendorProfileService
{
    public function __construct(private readonly VendorRepository $vendorRepository)
    {
    }

    public function create(string $userId, string $businessName, ?string $businessRegistrationNumber): array
    {
        $businessName = trim($businessName);

        if ($businessName === '') {
            throw new InvalidArgumentException('Business name is required.');
        }

        if (mb_strlen($businessName) > 255) {
            throw new InvalidArgumentException('Business name must not exceed 255 characters.');
        }

        if ($businessRegistrationNumber !== null) {
            $businessRegistrationNumber = trim($businessRegistrationNumber);

            if ($businessRegistrationNumber === '') {
                $businessRegistrationNumber = null;
            }

            if (
                $businessRegistrationNumber !== null
                && mb_strlen($businessRegistrationNumber) > 255
            ) {
                throw new InvalidArgumentException('Business registration number must not exceed 255 characters.');
            }
        }

        if ($this->vendorRepository->findByUserId($userId) !== null) {
            throw new VendorProfileAlreadyExistsException();
        }

        return $this->vendorRepository->createForUser(
            $userId,
            $businessName,
            $businessRegistrationNumber,
        );
    }

    public function getForUser(string $userId): ?array
    {
        return $this->vendorRepository->findByUserId($userId);
    }
}