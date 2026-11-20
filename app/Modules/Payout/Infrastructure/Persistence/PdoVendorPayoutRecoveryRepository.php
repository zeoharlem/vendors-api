<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Persistence;

use App\Modules\Payout\Domain\Repositories\VendorPayoutRecoveryRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoVendorPayoutRecoveryRepository implements VendorPayoutRecoveryRepository
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function createPending(array $data): array
    {
        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.vendor_payout_recoveries (
                    id,
                    payout_id,
                    vendor_payable_id,
                    store_id,
                    amount_minor,
                    currency,
                    return_method,
                    provider_reference,
                    confirmation_reference,
                    notes,
                    status,
                    recorded_by,
                    created_at,
                    updated_at
                )
                VALUES (
                    :id,
                    :payout_id,
                    :vendor_payable_id,
                    :store_id,
                    :amount_minor,
                    :currency,
                    :return_method,
                    :provider_reference,
                    :confirmation_reference,
                    :notes,
                    'pending',
                    :recorded_by,
                    NOW(),
                    NOW()
                )
                RETURNING *
            SQL,
            $data
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Unable to record payout recovery.');
        }

        return $row;
    }

    public function findByIdForUpdate(string $id): ?array
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.vendor_payout_recoveries
                WHERE id = :id
                FOR UPDATE
            SQL,
            ['id' => $id]
        );

        $row = $result->fetch();

        return is_array($row) ? $row : null;
    }

    public function sumReservedForPayout(
        string $payoutId,
        ?string $excludeRecoveryId = null
    ): int {
        $result = $this->db->query(
            <<<'SQL'
                SELECT COALESCE(SUM(amount_minor), 0) AS total
                FROM public.vendor_payout_recoveries
                WHERE payout_id = :payout_id
                  AND status IN ('pending', 'confirmed')
                  AND (
                      :exclude_id IS NULL
                      OR id <> CAST(:exclude_id AS UUID)
                  )
            SQL,
            [
                'payout_id' => $payoutId,
                'exclude_id' => $excludeRecoveryId,
            ]
        );

        $row = $result->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function markConfirmed(
        string $id,
        string $adminId
    ): array {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.vendor_payout_recoveries
                SET
                    status = 'confirmed',
                    confirmed_by = :admin_id,
                    confirmed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                  AND status = 'pending'
                RETURNING *
            SQL,
            [
                'id' => $id,
                'admin_id' => $adminId,
            ]
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new RuntimeException(
                'The payout recovery could not be confirmed.'
            );
        }

        return $row;
    }

    public function markRejected(
        string $id,
        string $adminId,
        ?string $reason
    ): array {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.vendor_payout_recoveries
                SET
                    status = 'rejected',
                    notes = CASE
                        WHEN :reason IS NULL OR :reason = ''
                            THEN notes
                        WHEN notes IS NULL OR notes = ''
                            THEN :reason
                        ELSE notes || E'\n' || :reason
                    END,
                    updated_at = NOW()
                WHERE id = :id
                  AND status = 'pending'
                RETURNING *
            SQL,
            [
                'id' => $id,
                'reason' => $reason,
            ]
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new RuntimeException(
                'The payout recovery could not be rejected.'
            );
        }

        return $row;
    }
}