<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Jobs;

use App\Modules\Vendor\Domain\Repository\VendorOrderRepository;

final class AutoAcceptExpiredDeliveriesJob
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(private readonly VendorOrderRepository $vendorOrderRepository)
    {
    }

    public function run(int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        if ($batchSize < 1 || $batchSize > 500) {
            throw new \InvalidArgumentException(
                'Batch size must be between 1 and 500.'
            );
        }

        return $this->vendorOrderRepository->autoCompleteExpiredDeliveries($batchSize);
    }
}