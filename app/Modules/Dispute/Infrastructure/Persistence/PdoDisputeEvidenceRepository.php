<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Persistence;

use App\Modules\Dispute\Domain\Entities\DisputeEvidence;
use App\Modules\Dispute\Domain\Repositories\DisputeEvidenceRepositoryInterface;
use DateTimeImmutable;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoDisputeEvidenceRepository implements DisputeEvidenceRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db
    ) {
    }

    public function create(DisputeEvidence $evidence): DisputeEvidence
    {
        $result = $this->db->query(
            <<<'SQL'
            INSERT INTO public.dispute_evidence (
                id,
                dispute_id,
                uploaded_by,
                original_filename,
                storage_key,
                mime_type,
                file_size_bytes,
                checksum_sha256,
                created_at
            )
            VALUES (
                :id,
                :dispute_id,
                :uploaded_by,
                :original_filename,
                :storage_key,
                :mime_type,
                :file_size_bytes,
                :checksum_sha256,
                :created_at
            )
            RETURNING *
            SQL,
            [
                'id' => $evidence->id,
                'dispute_id' => $evidence->disputeId,
                'uploaded_by' => $evidence->uploadedBy,
                'original_filename' => $evidence->originalFilename,
                'storage_key' => $evidence->storageKey,
                'mime_type' => $evidence->mimeType,
                'file_size_bytes' => $evidence->fileSizeBytes,
                'checksum_sha256' => $evidence->checksumSha256,
                'created_at' => $evidence->createdAt->format('Y-m-d H:i:s.uP'),
            ]
        );

        $row = $result->fetch();

        if ($row === false || $row === null) {
            throw new RuntimeException(
                'Unable to create dispute evidence record.'
            );
        }

        return $this->mapRow($row);
    }

    public function findById(string $evidenceId): ?DisputeEvidence
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT *
            FROM public.dispute_evidence
            WHERE id = :evidence_id
            LIMIT 1
            SQL,
            [
                'evidence_id' => $evidenceId,
            ]
        );

        $row = $result->fetch();

        return $row === false || $row === null
            ? null
            : $this->mapRow($row);
    }

    public function findByIdForDispute(
        string $evidenceId,
        string $disputeId
    ): ?DisputeEvidence {
        $result = $this->db->query(
            <<<'SQL'
            SELECT *
            FROM public.dispute_evidence
            WHERE id = :evidence_id
              AND dispute_id = :dispute_id
            LIMIT 1
            SQL,
            [
                'evidence_id' => $evidenceId,
                'dispute_id' => $disputeId,
            ]
        );

        $row = $result->fetch();

        return $row === false || $row === null
            ? null
            : $this->mapRow($row);
    }

    /**
     * @return list<DisputeEvidence>
     */
    public function listByDispute(
        string $disputeId,
        int $limit,
        int $offset
    ): array {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $result = $this->db->query(
            <<<'SQL'
            SELECT *
            FROM public.dispute_evidence
            WHERE dispute_id = :dispute_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
            OFFSET :offset
            SQL,
            [
                'dispute_id' => $disputeId,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );

        $rows = $result->fetchAll();

        $evidence = [];

        foreach ($rows as $row) {
            $evidence[] = $this->mapRow($row);
        }

        return $evidence;
    }

    public function delete(
        string $evidenceId,
        string $disputeId
    ): bool {
        $result = $this->db->query(
            <<<'SQL'
            DELETE FROM public.dispute_evidence
            WHERE id = :evidence_id
              AND dispute_id = :dispute_id
            RETURNING id
            SQL,
            [
                'evidence_id' => $evidenceId,
                'dispute_id' => $disputeId,
            ]
        );

        return $result->fetch() !== false;
    }

    private function mapRow(array $row): DisputeEvidence
    {
        return new DisputeEvidence(
            id: (string) $row['id'],
            disputeId: (string) $row['dispute_id'],
            uploadedBy: (string) $row['uploaded_by'],
            originalFilename: (string) $row['original_filename'],
            storageKey: (string) $row['storage_key'],
            mimeType: (string) $row['mime_type'],
            fileSizeBytes: (int) $row['file_size_bytes'],
            checksumSha256: $row['checksum_sha256'] !== null
            ? (string) $row['checksum_sha256']
            : null,
            createdAt: new DateTimeImmutable(
                (string) $row['created_at']
            )
        );
    }
}
