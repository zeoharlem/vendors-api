<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Repositories;

use App\Modules\Dispute\Domain\Entities\DisputeEvidence;

interface DisputeEvidenceRepositoryInterface
{
    public function create(DisputeEvidence $evidence): DisputeEvidence;

    public function findById(string $evidenceId): ?DisputeEvidence;

    public function findByIdForDispute(
        string $evidenceId,
        string $disputeId,
    ): ?DisputeEvidence;

    /**
     * @return list<DisputeEvidence>
     */
    public function listByDispute(
        string $disputeId,
        int $limit,
        int $offset,
    ): array;

    public function delete(string $evidenceId, string $disputeId): bool;
}
