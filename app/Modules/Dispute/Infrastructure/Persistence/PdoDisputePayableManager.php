<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Persistence;

use App\Modules\Dispute\Domain\Contracts\DisputePayableManagerInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoDisputePayableManager implements DisputePayableManagerInterface
{
    public function __construct(private AdapterInterface $db) {
    }

    /**
     * Holds a vendor payable and marks its vendor order as disputed.
     *
     * The caller must already have an active transaction.
     *
     * @return string Previous vendor order status.
     */
    public function holdForDispute(
        string $vendorOrderId,
        string $disputeId
    ): string {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vo.id::text AS vendor_order_id,
                vo.order_id::text AS order_id,
                vo.status AS vendor_order_status,
                vp.id::text AS vendor_payable_id,
                vp.status AS payable_status,
                vp.hold_reason
            FROM public.vendor_orders vo
            INNER JOIN public.vendor_payables vp
                ON vp.vendor_order_id = vo.id
            WHERE vo.id = :vendor_order_id
            LIMIT 1
            FOR UPDATE OF vo, vp
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
            ]
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new RuntimeException(
                'Vendor order or its payable could not be found.'
            );
        }

        $previousStatus = (string) $row['vendor_order_status'];

        $allowedVendorOrderStatuses = [
            'accepted',
            'processing',
            'ready_to_ship',
            'shipped',
            'delivered',
            'completed',
        ];

        if (!in_array($previousStatus, $allowedVendorOrderStatuses, true)) {
            throw new RuntimeException(
                sprintf(
                    'A dispute cannot be opened for a vendor order with status "%s".',
                    $previousStatus
                )
            );
        }

        $payableStatus = (string) $row['payable_status'];

        if (!in_array($payableStatus, ['held', 'eligible'], true)) {
            throw new RuntimeException(
                sprintf(
                    'The vendor payable cannot be placed on dispute hold because its status is "%s".',
                    $payableStatus
                )
            );
        }

        if ($row['hold_reason'] !== null) {
            throw new RuntimeException(
                'The vendor payable already has an active hold reason.'
            );
        }

        $vendorOrderResult = $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_orders
            SET
                status = 'disputed',
                updated_at = NOW()
            WHERE id = :vendor_order_id
              AND status = :previous_status
            RETURNING id
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
                'previous_status' => $previousStatus,
            ]
        );

        if (!is_array($vendorOrderResult->fetch())) {
            throw new RuntimeException(
                'Unable to mark the vendor order as disputed.'
            );
        }

        $payableResult = $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_payables
            SET
                status = 'on_hold',
                hold_reason = :hold_reason,
                updated_at = NOW()
            WHERE vendor_order_id = :vendor_order_id
              AND status = :previous_status
            RETURNING id
            SQL,
            [
                'hold_reason' => 'dispute:' . $disputeId,
                'vendor_order_id' => $vendorOrderId,
                'previous_status' => $payableStatus,
            ]
        );

        if (!is_array($payableResult->fetch())) {
            throw new RuntimeException(
                'Unable to place the vendor payable on hold.'
            );
        }

        return $previousStatus;
    }

    /**
     * Restores a vendor order after a vendor-favour resolution.
     *
     * The payable is released only if no other active dispute or
     * pending/processing refund requires it to remain on hold.
     *
     * The caller must already have an active transaction.
     */
    public function releaseAfterDispute(
        string $vendorOrderId,
        string $disputeId,
        string $previousVendorOrderStatus
    ): void {
        $allowedPreviousStatuses = [
            'accepted',
            'processing',
            'ready_to_ship',
            'shipped',
            'delivered',
            'completed',
        ];

        if (
            !in_array(
                $previousVendorOrderStatus,
                $allowedPreviousStatuses,
                true
            )
        ) {
            throw new RuntimeException(
                'The previous vendor order status is invalid.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vo.id::text AS vendor_order_id,
                vo.order_id::text AS order_id,
                vo.status AS vendor_order_status,
                vp.id::text AS vendor_payable_id,
                vp.status AS payable_status,
                vp.hold_reason
            FROM public.vendor_orders vo
            INNER JOIN public.vendor_payables vp
                ON vp.vendor_order_id = vo.id
            WHERE vo.id = :vendor_order_id
            LIMIT 1
            FOR UPDATE OF vo, vp
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
            ]
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new RuntimeException(
                'Vendor order or its payable could not be found.'
            );
        }

        if ($row['vendor_order_status'] !== 'disputed') {
            throw new RuntimeException(
                'The vendor order is not currently disputed.'
            );
        }

        if ($row['payable_status'] !== 'on_hold') {
            throw new RuntimeException(
                'The vendor payable is not currently on hold.'
            );
        }

        $expectedHoldReason = 'dispute:' . $disputeId;

        if ($row['hold_reason'] !== $expectedHoldReason) {
            throw new RuntimeException(
                'The payable hold does not belong to this dispute.'
            );
        }

        /*
         * Restore the vendor order to its previous status.
         */
        $vendorOrderResult = $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_orders
            SET
                status = :previous_status,
                updated_at = NOW()
            WHERE id = :vendor_order_id
              AND status = 'disputed'
            RETURNING id
            SQL,
            [
                'previous_status' => $previousVendorOrderStatus,
                'vendor_order_id' => $vendorOrderId,
            ]
        );

        if (!is_array($vendorOrderResult->fetch())) {
            throw new RuntimeException(
                'Unable to restore the vendor order status.'
            );
        }

        /*
         * Check whether another active dispute still requires a hold.
         *
         * The current dispute is excluded because it is being resolved
         * in favour of the vendor.
         */
        $holdCheckResult = $this->db->query(
            <<<'SQL'
            SELECT
                EXISTS (
                    SELECT 1
                    FROM public.disputes d
                    WHERE d.vendor_order_id = :vendor_order_id
                      AND d.id <> :dispute_id
                      AND d.status IN (
                          'open',
                          'under_review',
                          'awaiting_customer',
                          'awaiting_vendor'
                      )
                ) AS has_active_dispute,

                EXISTS (
                    SELECT 1
                    FROM public.refunds r
                    WHERE (
                        r.vendor_order_id = :refund_vendor_order_id
                        OR (
                            r.vendor_order_id IS NULL
                            AND r.order_id = :order_id
                        )
                    )
                    AND r.status IN ('pending', 'processing')
                ) AS has_pending_refund
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
                'dispute_id' => $disputeId,
                'refund_vendor_order_id' => $vendorOrderId,
                'order_id' => $row['order_id'],
            ]
        );

        $holdCheck = $holdCheckResult->fetch();

        if (!is_array($holdCheck)) {
            throw new RuntimeException(
                'Unable to verify outstanding dispute and refund holds.'
            );
        }

        $hasActiveDispute = filter_var(
            $holdCheck['has_active_dispute'],
            FILTER_VALIDATE_BOOLEAN
        );

        $hasPendingRefund = filter_var(
            $holdCheck['has_pending_refund'],
            FILTER_VALIDATE_BOOLEAN
        );

        /*
         * Do not release the payable while another financial hold exists.
         * Preserve the current hold reason for the other process to handle.
         */
        if ($hasActiveDispute || $hasPendingRefund) {
            return;
        }

        /*
         * A completed vendor order becomes eligible for payout.
         * An incomplete vendor order remains held.
         */
        $nextPayableStatus = $previousVendorOrderStatus === 'completed'
            ? 'eligible'
            : 'held';

        $eligibleAt = $nextPayableStatus === 'eligible'
            ? 'NOW()'
            : 'NULL';

        $releaseResult = $this->db->query(
            <<<SQL
            UPDATE public.vendor_payables
            SET
                status = :next_status,
                hold_reason = NULL,
                eligible_at = {$eligibleAt},
                updated_at = NOW()
            WHERE vendor_order_id = :vendor_order_id
              AND status = 'on_hold'
              AND hold_reason = :hold_reason
            RETURNING id
            SQL,
            [
                'next_status' => $nextPayableStatus,
                'vendor_order_id' => $vendorOrderId,
                'hold_reason' => $expectedHoldReason,
            ]
        );

        if (!is_array($releaseResult->fetch())) {
            throw new RuntimeException(
                'Unable to release the vendor payable after dispute resolution.'
            );
        }
    }
}
