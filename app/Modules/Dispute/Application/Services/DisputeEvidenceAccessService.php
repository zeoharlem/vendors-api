<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Services;

use App\Modules\Dispute\Domain\Contracts\DisputeEvidenceStorageInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeTransactionManagerInterface;
use App\Modules\Dispute\Domain\Entities\DisputeEvidence;
use App\Modules\Dispute\Domain\Entities\DisputeHistory;
use App\Modules\Dispute\Domain\Enums\DisputeEventType;
use App\Modules\Dispute\Domain\Repositories\DisputeEvidenceRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeHistoryRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeRepositoryInterface;
use DomainException;
use RuntimeException;

final readonly class DisputeEvidenceAccessService
{
    public function __construct(
        private DisputeRepositoryInterface $disputeRepository,
        private DisputeEvidenceRepositoryInterface $evidenceRepository,
        private DisputeHistoryRepositoryInterface $historyRepository,
        private DisputeEvidenceStorageInterface $storage,
        private DisputeTransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * @return array{
     *     evidence: DisputeEvidence,
     *     stream: resource
     * }
     */
    public function downloadForCustomer(
        string $disputeId,
        string $evidenceId,
        string $customerId
    ): array {
        $dispute = $this->disputeRepository->findByIdForCustomer(
            $disputeId,
            $customerId
        );

        if ($dispute === null) {
            throw new DomainException(
                'Dispute was not found or you do not have access to it.'
            );
        }

        return $this->openEvidence($disputeId, $evidenceId);
    }

    /**
     * @return array{
     *     evidence: DisputeEvidence,
     *     stream: resource
     * }
     */
    public function downloadForStore(
        string $disputeId,
        string $evidenceId,
        string $storeId
    ): array {
        $dispute = $this->disputeRepository->findByIdForStore(
            $disputeId,
            $storeId
        );

        if ($dispute === null) {
            throw new DomainException(
                'Dispute was not found or you do not have access to it.'
            );
        }

        return $this->openEvidence($disputeId, $evidenceId);
    }

    public function deleteForCustomer(
        string $disputeId,
        string $evidenceId,
        string $customerId
    ): void {
        $dispute = $this->disputeRepository->findByIdForCustomer(
            $disputeId,
            $customerId
        );

        if ($dispute === null) {
            throw new DomainException(
                'Dispute was not found or you do not have access to it.'
            );
        }

        $this->deleteEvidence(
            $disputeId,
            $evidenceId,
            $customerId
        );
    }

    public function deleteForStore(
        string $disputeId,
        string $evidenceId,
        string $storeId,
        string $actorId
    ): void {
        $dispute = $this->disputeRepository->findByIdForStore(
            $disputeId,
            $storeId
        );

        if ($dispute === null) {
            throw new DomainException(
                'Dispute was not found or you do not have access to it.'
            );
        }

        $this->deleteEvidence(
            $disputeId,
            $evidenceId,
            $actorId
        );
    }

    /**
     * @return array{
     *     evidence: DisputeEvidence,
     *     stream: resource
     * }
     */
    private function openEvidence(
        string $disputeId,
        string $evidenceId
    ): array {
        $evidence = $this->evidenceRepository->findByIdForDispute(
            $evidenceId,
            $disputeId
        );

        if ($evidence === null) {
            throw new DomainException(
                'Dispute evidence was not found.'
            );
        }

        $stream = $this->storage->read($evidence->storageKey);

        if (!is_resource($stream)) {
            throw new RuntimeException(
                'Unable to access the stored dispute evidence.'
            );
        }

        return [
            'evidence' => $evidence,
            'stream' => $stream,
        ];
    }

    private function deleteEvidence(
        string $disputeId,
        string $evidenceId,
        string $actorId
    ): void {
        $storageKey = $this->transactionManager->transactional(
            function () use ($disputeId, $evidenceId, $actorId): string {
                $dispute = $this->disputeRepository->findByIdForUpdate(
                    $disputeId
                );

                if ($dispute === null) {
                    throw new DomainException(
                        'Dispute was not found.'
                    );
                }

                if (!$dispute->status()->isActive()) {
                    throw new DomainException(
                        'Evidence cannot be deleted from a resolved or closed dispute.'
                    );
                }

                $evidence = $this->evidenceRepository->findByIdForDispute(
                    $evidenceId,
                    $disputeId
                );

                if ($evidence === null) {
                    throw new DomainException(
                        'Dispute evidence was not found.'
                    );
                }

                if ($evidence->uploadedBy !== $actorId) {
                    throw new DomainException(
                        'You can only delete evidence that you uploaded.'
                    );
                }

                $deleted = $this->evidenceRepository->delete(
                    $evidenceId,
                    $disputeId
                );

                if (!$deleted) {
                    throw new RuntimeException(
                        'Unable to delete dispute evidence metadata.'
                    );
                }

                $history = new DisputeHistory(
                    id: $this->generateUuid(),
                    disputeId: $disputeId,
                    actorId: $actorId,
                    eventType: DisputeEventType::EVIDENCE_DELETED,
                    previousStatus: $dispute->status(),
                    newStatus: $dispute->status(),
                    note: 'Dispute evidence deleted.',
                    metadata: [
                        'evidence_id' => $evidence->id,
                        'original_filename' => $evidence->originalFilename,
                        'mime_type' => $evidence->mimeType,
                        'file_size_bytes' => $evidence->fileSizeBytes,
                        'checksum_sha256' => $evidence->checksumSha256,
                    ],
                    createdAt: new \DateTimeImmutable()
                );

                $this->historyRepository->append($history);

                return $evidence->storageKey;
            }
        );

        // The database transaction has committed at this point.
        // A failed physical deletion leaves a private orphan file.
        $this->storage->delete($storageKey);
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
