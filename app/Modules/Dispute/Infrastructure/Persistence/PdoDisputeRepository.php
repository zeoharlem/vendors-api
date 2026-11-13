<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Persistence;

use App\Modules\Dispute\Domain\Entities\Dispute;
use App\Modules\Dispute\Domain\Enums\DisputeResolutionOutcome;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use App\Modules\Dispute\Domain\Repositories\DisputeRepositoryInterface;
use App\Modules\Dispute\Domain\ValueObjects\DisputeOrderContext;
use DateTimeImmutable;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoDisputeRepository implements DisputeRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function create(Dispute $dispute): Dispute
    {
        $result = $this->db->query(
            <<<'SQL'
            INSERT INTO public.disputes (
                id,
                order_id,
                vendor_order_id,
                vendor_payable_id,
                opened_by,
                reason,
                description,
                status,
                resolution,
                resolution_outcome,
                resolved_by,
                resolved_at,
                previous_vendor_order_status,
                closed_at,
                created_at,
                updated_at
            )
            VALUES (
                :id,
                :order_id,
                :vendor_order_id,
                :vendor_payable_id,
                :opened_by,
                :reason,
                :description,
                :status,
                :resolution,
                :resolution_outcome,
                :resolved_by,
                :resolved_at,
                :previous_vendor_order_status,
                :closed_at,
                :created_at,
                :updated_at
            )
            RETURNING *
            SQL,
            $this->toDatabaseParameters($dispute)
        );

        $row = $result->fetch();

        if ($row === false || $row === null) {
            throw new RuntimeException('Failed to create dispute.');
        }

        return $this->mapRow($row);
    }

    public function save(Dispute $dispute): void
    {
        $parameters = $this->toDatabaseParameters($dispute);

        $this->db->query(
            <<<'SQL'
            UPDATE public.disputes
            SET
                vendor_payable_id = :vendor_payable_id,
                reason = :reason,
                description = :description,
                status = :status,
                resolution = :resolution,
                resolution_outcome = :resolution_outcome,
                resolved_by = :resolved_by,
                resolved_at = :resolved_at,
                previous_vendor_order_status = :previous_vendor_order_status,
                closed_at = :closed_at,
                updated_at = :updated_at
            WHERE id = :id
            SQL,
            [
                'id' => $parameters['id'],
                'vendor_payable_id' => $parameters['vendor_payable_id'],
                'reason' => $parameters['reason'],
                'description' => $parameters['description'],
                'status' => $parameters['status'],
                'resolution' => $parameters['resolution'],
                'resolution_outcome' => $parameters['resolution_outcome'],
                'resolved_by' => $parameters['resolved_by'],
                'resolved_at' => $parameters['resolved_at'],
                'previous_vendor_order_status' => $parameters['previous_vendor_order_status'],
                'closed_at' => $parameters['closed_at'],
                'updated_at' => $parameters['updated_at'],
            ]
        );
    }

    public function findById(string $disputeId): ?Dispute
    {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.disputes
            WHERE id = :dispute_id
            LIMIT 1
            SQL,
            ['dispute_id' => $disputeId]
        );
    }

    public function findByIdForUpdate(string $disputeId): ?Dispute
    {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.disputes
            WHERE id = :dispute_id
            LIMIT 1
            FOR UPDATE
            SQL,
            ['dispute_id' => $disputeId]
        );
    }

    public function findByIdForCustomer(
        string $disputeId,
        string $customerId
    ): ?Dispute {
        return $this->findOne(
            <<<'SQL'
            SELECT d.*
            FROM public.disputes d
            INNER JOIN public.orders o
                ON o.id = d.order_id
            WHERE d.id = :dispute_id
              AND o.customer_id = :customer_id
            LIMIT 1
            SQL,
            [
                'dispute_id' => $disputeId,
                'customer_id' => $customerId,
            ]
        );
    }

    public function findOrderContextForCustomer(
        string $orderId,
        string $customerId
    ): ?DisputeOrderContext {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                o.id AS order_id,
                o.customer_id,
                vo.id AS vendor_order_id,
                vo.status AS vendor_order_status
            FROM public.orders o
            INNER JOIN public.vendor_orders vo
                ON vo.order_id = o.id
            WHERE o.id = :order_id
              AND o.customer_id = :customer_id
            LIMIT 1
            SQL,
            [
                'order_id' => $orderId,
                'customer_id' => $customerId,
            ]
        );
        $row = $result->fetch();

        return $row === false || $row === null ? null : $row;
    }

    public function findByIdForStore(
        string $disputeId,
        string $storeId
    ): ?Dispute {
        return $this->findOne(
            <<<'SQL'
            SELECT d.*
            FROM public.disputes d
            INNER JOIN public.vendor_orders vo
                ON vo.id = d.vendor_order_id
            WHERE d.id = :dispute_id
              AND vo.store_id = :store_id
            LIMIT 1
            SQL,
            [
                'dispute_id' => $disputeId,
                'store_id' => $storeId,
            ]
        );
    }

    public function findActiveByVendorOrder(
        string $vendorOrderId
    ): ?Dispute {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.disputes
            WHERE vendor_order_id = :vendor_order_id
              AND status IN (
                  'open',
                  'under_review',
                  'awaiting_customer',
                  'awaiting_vendor'
              )
            ORDER BY created_at DESC
            LIMIT 1
            SQL,
            ['vendor_order_id' => $vendorOrderId]
        );
    }

    public function findActiveByVendorOrderForUpdate(
        string $vendorOrderId
    ): ?Dispute {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.disputes
            WHERE vendor_order_id = :vendor_order_id
              AND status IN (
                  'open',
                  'under_review',
                  'awaiting_customer',
                  'awaiting_vendor'
              )
            ORDER BY created_at DESC
            LIMIT 1
            FOR UPDATE
            SQL,
            ['vendor_order_id' => $vendorOrderId]
        );
    }

    public function listForCustomer(
        string $customerId,
        ?DisputeStatus $status,
        int $limit,
        int $offset
    ): array {
        return $this->fetchDisputes(
            <<<'SQL'
            SELECT d.*
            FROM public.disputes d
            INNER JOIN public.orders o
                ON o.id = d.order_id
            WHERE o.customer_id = :customer_id
              AND (:status IS NULL OR d.status = :status)
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT :limit OFFSET :offset
            SQL,
            [
                'customer_id' => $customerId,
                'status' => $status?->value,
                'limit' => $this->normalizeLimit($limit),
                'offset' => max(0, $offset),
            ]
        );
    }

    public function listForStore(
        string $storeId,
        ?DisputeStatus $status,
        int $limit,
        int $offset
    ): array {
        return $this->fetchDisputes(
            <<<'SQL'
            SELECT d.*
            FROM public.disputes d
            INNER JOIN public.vendor_orders vo
                ON vo.id = d.vendor_order_id
            WHERE vo.store_id = :store_id
              AND (:status IS NULL OR d.status = :status)
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT :limit OFFSET :offset
            SQL,
            [
                'store_id' => $storeId,
                'status' => $status?->value,
                'limit' => $this->normalizeLimit($limit),
                'offset' => max(0, $offset),
            ]
        );
    }

    public function listForAdmin(
        ?DisputeStatus $status,
        int $limit,
        int $offset
    ): array {
        return $this->fetchDisputes(
            <<<'SQL'
            SELECT d.*
            FROM public.disputes d
            WHERE (:status IS NULL OR d.status = :status)
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT :limit OFFSET :offset
            SQL,
            [
                'status' => $status?->value,
                'limit' => $this->normalizeLimit($limit),
                'offset' => max(0, $offset),
            ]
        );
    }

    public function countForCustomer(
        string $customerId,
        ?DisputeStatus $status
    ): int {
        return $this->count(
            <<<'SQL'
            SELECT COUNT(*) AS total
            FROM public.disputes d
            INNER JOIN public.orders o
                ON o.id = d.order_id
            WHERE o.customer_id = :customer_id
              AND (:status IS NULL OR d.status = :status)
            SQL,
            [
                'customer_id' => $customerId,
                'status' => $status?->value,
            ]
        );
    }

    public function countForStore(
        string $storeId,
        ?DisputeStatus $status
    ): int {
        return $this->count(
            <<<'SQL'
            SELECT COUNT(*) AS total
            FROM public.disputes d
            INNER JOIN public.vendor_orders vo
                ON vo.id = d.vendor_order_id
            WHERE vo.store_id = :store_id
              AND (:status IS NULL OR d.status = :status)
            SQL,
            [
                'store_id' => $storeId,
                'status' => $status?->value,
            ]
        );
    }

    public function countForAdmin(?DisputeStatus $status): int
    {
        return $this->count(
            <<<'SQL'
            SELECT COUNT(*) AS total
            FROM public.disputes d
            WHERE (:status IS NULL OR d.status = :status)
            SQL,
            [
                'status' => $status?->value,
            ]
        );
    }

    private function findOne(string $sql, array $parameters): ?Dispute
    {
        $result = $this->db->query($sql, $parameters);
        $row = $result->fetch();

        if ($row === false || $row === null) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return list<Dispute>
     */
    private function fetchDisputes(string $sql, array $parameters): array
    {
        $result = $this->db->query($sql, $parameters);
        $rows = $result->fetchAll();

        return array_map(
            fn(array $row): Dispute => $this->mapRow($row),
            $rows
        );
    }

    private function count(string $sql, array $parameters): int
    {
        $result = $this->db->query($sql, $parameters);
        $row = $result->fetch();

        return (int) ($row['total'] ?? 0);
    }

    private function mapRow(array $row): Dispute
    {
        return new Dispute(
            id: (string) $row['id'],
            orderId: (string) $row['order_id'],
            vendorOrderId: (string) $row['vendor_order_id'],
            vendorPayableId: $row['vendor_payable_id'] !== null
            ? (string) $row['vendor_payable_id']
            : null,
            openedBy: (string) $row['opened_by'],
            reason: (string) $row['reason'],
            description: (string) $row['description'],
            status: DisputeStatus::from((string) $row['status']),
            resolution: $row['resolution'] !== null
            ? (string) $row['resolution']
            : null,
            resolutionOutcome: $row['resolution_outcome'] !== null
            ? DisputeResolutionOutcome::from(
                (string) $row['resolution_outcome']
            )
            : null,
            resolvedBy: $row['resolved_by'] !== null
            ? (string) $row['resolved_by']
            : null,
            resolvedAt: $this->toDateTime($row['resolved_at']),
            previousVendorOrderStatus: $row['previous_vendor_order_status'] !== null
            ? (string) $row['previous_vendor_order_status']
            : null,
            closedAt: $this->toDateTime($row['closed_at']),
            createdAt: $this->toDateTime($row['created_at']),
            updatedAt: $this->toDateTime($row['updated_at']),
        );
    }

    private function toDatabaseParameters(Dispute $dispute): array
    {
        return [
            'id' => $dispute->id(),
            'order_id' => $dispute->orderId(),
            'vendor_order_id' => $dispute->vendorOrderId(),
            'vendor_payable_id' => $dispute->vendorPayableId(),
            'opened_by' => $dispute->openedBy(),
            'reason' => $dispute->reason(),
            'description' => $dispute->description(),
            'status' => $dispute->status()->value,
            'resolution' => $dispute->resolution(),
            'resolution_outcome' => $dispute->resolutionOutcome()?->value,
            'resolved_by' => $dispute->resolvedBy(),
            'resolved_at' => $this->formatDateTime($dispute->resolvedAt()),
            'previous_vendor_order_status' => $dispute->previousVendorOrderStatus(),
            'closed_at' => $this->formatDateTime($dispute->closedAt()),
            'created_at' => $this->formatDateTime($dispute->createdAt()),
            'updated_at' => $this->formatDateTime($dispute->updatedAt()),
        ];
    }

    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return new DateTimeImmutable((string) $value);
    }

    private function formatDateTime(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s.uP');
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }
}
