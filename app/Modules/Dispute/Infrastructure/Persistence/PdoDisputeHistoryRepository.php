<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Persistence;

use App\Modules\Dispute\Domain\Entities\DisputeHistory;
use App\Modules\Dispute\Domain\Enums\DisputeEventType;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use App\Modules\Dispute\Domain\Repositories\DisputeHistoryRepositoryInterface;
use DateTimeImmutable;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoDisputeHistoryRepository implements DisputeHistoryRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function append(DisputeHistory $history): DisputeHistory
    {
        $result = $this->db->query(
            <<<'SQL'
            INSERT INTO public.dispute_history (
                id,
                dispute_id,
                actor_id,
                event_type,
                previous_status,
                new_status,
                note,
                metadata,
                created_at
            )
            VALUES (
                :id,
                :dispute_id,
                :actor_id,
                :event_type,
                :previous_status,
                :new_status,
                :note,
                CAST(:metadata AS jsonb),
                :created_at
            )
            RETURNING *
            SQL,
            [
                'id' => $history->id,
                'dispute_id' => $history->disputeId,
                'actor_id' => $history->actorId,
                'event_type' => $history->eventType->value,
                'previous_status' => $history->previousStatus?->value,
                'new_status' => $history->newStatus?->value,
                'note' => $history->note,
                'metadata' => json_encode($history->metadata,JSON_THROW_ON_ERROR),
                'created_at' => $history->createdAt->format('Y-m-d H:i:s.uP'),
            ]
        );

        $row = $result->fetch();

        if ($row === false || $row === null) {
            throw new RuntimeException('Failed to append dispute history.');
        }

        return $this->mapRow($row);
    }

    /**
     * @return list<DisputeHistory>
     */
    public function listByDispute(
        string $disputeId,
        int $limit,
        int $offset
    ): array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT *
            FROM public.dispute_history
            WHERE dispute_id = :dispute_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
            SQL,
            [
                'dispute_id' => $disputeId,
                'limit' => max(1, min($limit, 100)),
                'offset' => max(0, $offset),
            ]
        );

        $rows = $result->fetchAll();

        return array_map(
            fn(array $row): DisputeHistory => $this->mapRow($row),
            $rows
        );
    }

    private function mapRow(array $row): DisputeHistory
    {
        $metadata = $row['metadata'] ?? [];

        if (is_string($metadata)) {
            $metadata = json_decode(
                $metadata,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        return new DisputeHistory(
            id: (string) $row['id'],
            disputeId: (string) $row['dispute_id'],
            actorId: $row['actor_id'] !== null
            ? (string) $row['actor_id']
            : null,
            eventType: DisputeEventType::from((string) $row['event_type']),
            previousStatus: $row['previous_status'] !== null
            ? DisputeStatus::from((string) $row['previous_status'])
            : null,
            newStatus: $row['new_status'] !== null
            ? DisputeStatus::from((string) $row['new_status'])
            : null,
            note: $row['note'] !== null ? (string) $row['note'] : null,
            metadata: $metadata,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
