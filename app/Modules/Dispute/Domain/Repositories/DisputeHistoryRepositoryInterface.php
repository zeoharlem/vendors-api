<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Repositories;

use App\Modules\Dispute\Domain\Entities\DisputeHistory;

interface DisputeHistoryRepositoryInterface
{
    public function append(DisputeHistory $history): DisputeHistory;

    /**
     * @return list<DisputeHistory>
     */
    public function listByDispute(
        string $disputeId,
        int $limit,
        int $offset,
    ): array;
}
