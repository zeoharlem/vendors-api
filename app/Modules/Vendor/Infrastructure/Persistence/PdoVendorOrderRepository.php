<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Infrastructure\Persistence;

use App\Modules\Vendor\Domain\Repository\VendorOrderRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PdoVendorOrderRepository implements VendorOrderRepository
{
    public function __construct(
        private readonly AdapterInterface $db
    ) {
    }

    public function findByIdForStore(
        string $vendorOrderId,
        string $storeId
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vo.*
            FROM public.vendor_orders vo
            WHERE vo.id = :vendor_order_id
              AND vo.store_id = :store_id
            LIMIT 1
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
                'store_id' => $storeId,
            ]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    public function listByStore(
        string $storeId,
        ?string $status,
        int $limit,
        int $offset
    ): array {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException(
                'Limit must be between 1 and 100.'
            );
        }

        if ($offset < 0) {
            throw new InvalidArgumentException(
                'Offset cannot be negative.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vo.*
            FROM public.vendor_orders vo
            WHERE vo.store_id = :store_id
              AND (
                    :status_filter = TRUE
                    OR vo.status = :status_value
              )
            ORDER BY vo.created_at DESC, vo.id DESC
            LIMIT :limit
            OFFSET :offset
            SQL,
            [
                'store_id' => $storeId,
                'status_value' => $status,
                'status_filter' => $status === null,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );

        return $result->fetchAll();
    }

    public function countByStore(
        string $storeId,
        ?string $status
    ): int {
        $result = $this->db->query(
            <<<'SQL'
            SELECT COUNT(*) AS total
            FROM public.vendor_orders vo
            WHERE vo.store_id = :store_id
              AND (
                    :status_filter = TRUE
                    OR vo.status = :status_value
              )
            SQL,
            [
                'store_id' => $storeId,
                'status_filter' => $status === null,
                'status_value' => $status,
            ]
        );

        $row = $result->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function updateStatus(
        string $vendorOrderId,
        string $storeId,
        string $currentStatus,
        string $newStatus,
        array $additionalData = []
    ): ?array {
        $allowedFields = [
            'tracking_number',
            'tracking_url',
            'acceptance_deadline',
        ];

        foreach ($additionalData as $field => $value) {
            if (!in_array($field, $allowedFields, true)) {
                throw new InvalidArgumentException(
                    sprintf('Unsupported vendor order field: %s', $field)
                );
            }
        }

        $trackingNumber = $additionalData['tracking_number'] ?? null;
        $trackingUrl = $additionalData['tracking_url'] ?? null;
        $acceptanceDeadline = $additionalData['acceptance_deadline'] ?? null;

        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.vendor_orders
                SET
                    status = :new_status,

                    accepted_at = CASE
                        WHEN :accepted_status = 'accepted'
                        THEN COALESCE(accepted_at, NOW())
                        ELSE accepted_at
                    END,

                    shipped_at = CASE
                        WHEN :shipped_status = 'shipped'
                        THEN COALESCE(shipped_at, NOW())
                        ELSE shipped_at
                    END,

                    delivered_at = CASE
                        WHEN :delivered_status = 'delivered'
                        THEN COALESCE(delivered_at, NOW())
                        ELSE delivered_at
                    END,

                    acceptance_deadline = CASE
                        WHEN :acceptance_deadline_status = 'delivered'
                        THEN COALESCE(acceptance_deadline, :acceptance_deadline)
                        ELSE acceptance_deadline
                    END,

                    completed_at = CASE
                        WHEN :completed_status = 'completed'
                        THEN COALESCE(completed_at, NOW())
                        ELSE completed_at
                    END,

                    tracking_number = COALESCE(
                        :tracking_number,
                        tracking_number
                    ),

                    tracking_url = COALESCE(
                        :tracking_url,
                        tracking_url
                    ),

                    updated_at = NOW()
                WHERE id = :vendor_order_id
                AND store_id = :store_id
                AND status = :current_status
                RETURNING *
                SQL,
            [
                'new_status' => $newStatus,
                'accepted_status' => $newStatus,
                'shipped_status' => $newStatus,
                'delivered_status' => $newStatus,
                'completed_status' => $newStatus,
                'tracking_number' => $trackingNumber,
                'tracking_url' => $trackingUrl,
                'vendor_order_id' => $vendorOrderId,
                'store_id' => $storeId,
                'current_status' => $currentStatus,
                'acceptance_deadline_status' => $newStatus,
                'acceptance_deadline' => $acceptanceDeadline,
            ]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    public function confirmCustomerDelivery(
        string $customerId,
        string $vendorOrderId
    ): ?array {
        $this->db->begin();

        try {
            /*
             * 1. Lock the vendor order and parent order.
             * This also verifies that the authenticated customer owns it.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        vo.id,
                        vo.order_id,
                        vo.store_id,
                        vo.status,
                        vo.customer_confirmed_at,
                        o.customer_id
                    FROM public.vendor_orders vo
                    INNER JOIN public.orders o
                        ON o.id = vo.order_id
                    WHERE vo.id = :vendor_order_id
                    AND o.customer_id = :customer_id
                    FOR UPDATE OF vo, o
                SQL,
                [
                    'vendor_order_id' => $vendorOrderId,
                    'customer_id' => $customerId,
                ]
            );

            $vendorOrder = $result->fetch();

            if ($vendorOrder === false) {
                $this->db->rollback();
                return null;
            }

            /*
             * 2. Make confirmation idempotent.
             */
            if (
                $vendorOrder['status'] === 'completed'
                && $vendorOrder['customer_confirmed_at'] !== null
            ) {
                $this->db->commit();

                return $vendorOrder;
            }

            if ($vendorOrder['status'] !== 'delivered') {
                throw new RuntimeException(
                    'Only delivered vendor orders can be confirmed.'
                );
            }

            /*
             * 3. Check active disputes.
             */
            $disputeResult = $this->db->query(
                <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM public.disputes
                        WHERE vendor_order_id = :vendor_order_id
                        AND status IN ('open', 'under_review')
                    ) AS has_active_dispute
                SQL,
                [
                    'vendor_order_id' => $vendorOrderId,
                ]
            );

            $dispute = $disputeResult->fetch();

            $hasActiveDispute = filter_var(
                $dispute['has_active_dispute'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            /*
             * 4. Check pending, processing and successful refunds.
             *
             * Successful refunds are also treated as a blocker here
             * until the refund accounting workflow has reconciled
             * the corresponding vendor payable.
             */
            $refundResult = $this->db->query(
                <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM public.refunds
                        WHERE vendor_order_id = :vendor_order_id
                        AND status IN ('pending', 'processing', 'success')
                    ) AS has_refund_hold
                SQL,
                [
                    'vendor_order_id' => $vendorOrderId,
                ]
            );

            $refund = $refundResult->fetch();

            $hasRefundHold = filter_var(
                $refund['has_refund_hold'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            /*
             * 5. Lock the vendor payable.
             */
            $payableResult = $this->db->query(
                <<<'SQL'
                    SELECT
                        id,
                        status,
                        hold_reason,
                        payable_minor,
                        refund_minor
                    FROM public.vendor_payables
                    WHERE vendor_order_id = :vendor_order_id
                    FOR UPDATE
                SQL,
                [
                    'vendor_order_id' => $vendorOrderId,
                ]
            );

            $payable = $payableResult->fetch();

            if ($payable === false) {
                throw new RuntimeException(
                    'Vendor payable record was not found.'
                );
            }

            /*
             * 6. Complete the vendor order.
             */
            $updateResult = $this->db->query(
                <<<'SQL'
                    UPDATE public.vendor_orders
                    SET
                        status = 'completed',
                        customer_confirmed_at = NOW(),
                        completed_at = COALESCE(completed_at, NOW()),
                        updated_at = NOW()
                    WHERE id = :vendor_order_id
                    AND status = 'delivered'
                    AND customer_confirmed_at IS NULL
                    RETURNING *
                SQL,
                [
                    'vendor_order_id' => $vendorOrderId,
                ]
            );

            $completedOrder = $updateResult->fetch();

            if ($completedOrder === false) {
                throw new RuntimeException(
                    'Vendor order could not be confirmed.'
                );
            }

            /*
             * 7. Determine whether the payable can become eligible.
             *
             * Only transition a held payable.
             * Never automatically clear an existing on_hold status.
             */
            if ($payable['status'] === 'held') {
                if ($hasActiveDispute || $hasRefundHold) {
                    $reasons = [];

                    if ($hasActiveDispute) {
                        $reasons[] = 'Active dispute';
                    }

                    if ($hasRefundHold) {
                        $reasons[] = 'Refund requires reconciliation';
                    }

                    $this->db->query(
                        <<<'SQL'
                            UPDATE public.vendor_payables
                            SET
                                status = 'on_hold',
                                hold_reason = :hold_reason,
                                updated_at = NOW()
                            WHERE id = :payable_id
                            AND status = 'held'
                        SQL,
                        [
                            'payable_id' => $payable['id'],
                            'hold_reason' => implode('; ', $reasons),
                        ]
                    );
                } else {
                    $this->db->query(
                        <<<'SQL'
                            UPDATE public.vendor_payables
                            SET
                                status = 'eligible',
                                hold_reason = NULL,
                                eligible_at = COALESCE(eligible_at, NOW()),
                                updated_at = NOW()
                            WHERE id = :payable_id
                            AND status = 'held'
                        SQL,
                        [
                            'payable_id' => $payable['id'],
                        ]
                    );
                }
            }

            /*
             * 8. Record vendor order status history.
             */
            $this->db->query(
                <<<'SQL'
                    INSERT INTO public.order_status_history (
                        order_id,
                        vendor_order_id,
                        old_status,
                        new_status,
                        changed_by,
                        reason,
                        metadata,
                        created_at
                    )
                    VALUES (
                        :order_id,
                        :vendor_order_id,
                        'delivered',
                        'completed',
                        :customer_id,
                        'Customer confirmed delivery',
                        CAST(:metadata AS JSONB),
                        NOW()
                    )
                SQL,
                [
                    'order_id' => $vendorOrder['order_id'],
                    'vendor_order_id' => $vendorOrderId,
                    'customer_id' => $customerId,
                    'metadata' => json_encode([
                        'payable_status_before' => $payable['status'],
                        'active_dispute' => $hasActiveDispute,
                        'refund_hold' => $hasRefundHold,
                    ], JSON_THROW_ON_ERROR),
                ]
            );

            /*
             * 9. Return the completed vendor order.
             */
            $this->db->commit();

            return $completedOrder;

        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    public function autoCompleteExpiredDeliveries(
        int $limit = 100
    ): int {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException(
                'Limit must be between 1 and 500.'
            );
        }

        $completedCount = 0;

        $this->db->begin();

        try {
            /*
             * Lock expired vendor orders.
             *
             * SKIP LOCKED allows multiple cron workers to run
             * without processing the same vendor order.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        vo.id,
                        vo.order_id,
                        vo.store_id,
                        vo.status
                    FROM public.vendor_orders vo
                    WHERE vo.status = 'delivered'
                    AND vo.customer_confirmed_at IS NULL
                    AND vo.acceptance_deadline IS NOT NULL
                    AND vo.acceptance_deadline <= NOW()
                    ORDER BY vo.acceptance_deadline ASC, vo.id ASC
                    LIMIT :batch_limit
                    FOR UPDATE OF vo SKIP LOCKED
                SQL,
                [
                    'batch_limit' => $limit,
                ]
            );

            $vendorOrders = $result->fetchAll();

            foreach ($vendorOrders as $vendorOrder) {
                $vendorOrderId = $vendorOrder['id'];

                /*
                 * Check active disputes.
                 */
                $disputeResult = $this->db->query(
                    <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM public.disputes
                    WHERE vendor_order_id = :vendor_order_id
                      AND status IN ('open', 'under_review')
                ) AS has_active_dispute
                SQL,
                    [
                        'vendor_order_id' => $vendorOrderId,
                    ]
                );

                $dispute = $disputeResult->fetch();

                $hasActiveDispute = filter_var(
                    $dispute['has_active_dispute'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

                /*
                 * Pending, processing and successful refunds
                 * require financial reconciliation.
                 */
                $refundResult = $this->db->query(
                    <<<'SQL'
                        SELECT EXISTS (
                            SELECT 1
                            FROM public.refunds
                            WHERE vendor_order_id = :vendor_order_id
                            AND status IN ('pending', 'processing', 'success')
                        ) AS has_refund_hold
                    SQL,
                    [
                        'vendor_order_id' => $vendorOrderId,
                    ]
                );

                $refund = $refundResult->fetch();

                $hasRefundHold = filter_var(
                    $refund['has_refund_hold'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

                /*
                 * Lock the payable before changing its status.
                 */
                $payableResult = $this->db->query(
                    <<<'SQL'
                        SELECT
                            id,
                            status
                        FROM public.vendor_payables
                        WHERE vendor_order_id = :vendor_order_id
                        FOR UPDATE
                    SQL,
                    [
                        'vendor_order_id' => $vendorOrderId,
                    ]
                );

                $payable = $payableResult->fetch();

                if ($payable === false) {
                    throw new RuntimeException(
                        sprintf(
                            'Vendor payable not found for vendor order %s.',
                            $vendorOrderId
                        )
                    );
                }

                /*
                 * Complete the vendor order.
                 *
                 * customer_confirmed_at remains NULL because
                 * this was automatic acceptance, not customer action.
                 */
                $updateResult = $this->db->query(
                    <<<'SQL'
                        UPDATE public.vendor_orders
                        SET
                            status = 'completed',
                            completed_at = COALESCE(completed_at, NOW()),
                            updated_at = NOW()
                        WHERE id = :vendor_order_id
                        AND status = 'delivered'
                        AND customer_confirmed_at IS NULL
                        AND acceptance_deadline <= NOW()
                        RETURNING *
                    SQL,
                    [
                        'vendor_order_id' => $vendorOrderId,
                    ]
                );

                $completedOrder = $updateResult->fetch();

                if ($completedOrder === false) {
                    continue;
                }

                /*
                 * Evaluate payable eligibility.
                 *
                 * Only held payables can transition automatically.
                 * Existing on_hold and other statuses are preserved.
                 */
                if ($payable['status'] === 'held') {
                    if ($hasActiveDispute || $hasRefundHold) {
                        $reasons = [];

                        if ($hasActiveDispute) {
                            $reasons[] = 'Active dispute';
                        }

                        if ($hasRefundHold) {
                            $reasons[] = 'Refund requires reconciliation';
                        }

                        $this->db->query(
                            <<<'SQL'
                                UPDATE public.vendor_payables
                                SET
                                    status = 'on_hold',
                                    hold_reason = :hold_reason,
                                    updated_at = NOW()
                                WHERE id = :payable_id
                                AND status = 'held'
                            SQL,
                            [
                                'payable_id' => $payable['id'],
                                'hold_reason' => implode('; ', $reasons),
                            ]
                        );
                    } else {
                        $this->db->query(
                            <<<'SQL'
                                UPDATE public.vendor_payables
                                SET
                                    status = 'eligible',
                                    hold_reason = NULL,
                                    eligible_at = COALESCE(eligible_at, NOW()),
                                    updated_at = NOW()
                                WHERE id = :payable_id
                                AND status = 'held'
                            SQL,
                            [
                                'payable_id' => $payable['id'],
                            ]
                        );
                    }
                }

                /*
                 * Record automatic acceptance in order history.
                 *
                 * changed_by is NULL because this is a system action.
                 */
                $this->db->query(
                    <<<'SQL'
                        INSERT INTO public.order_status_history (
                            order_id,
                            vendor_order_id,
                            old_status,
                            new_status,
                            changed_by,
                            reason,
                            metadata,
                            created_at
                        )
                        VALUES (
                            :order_id,
                            :vendor_order_id,
                            'delivered',
                            'completed',
                            NULL,
                            'Automatically completed after customer acceptance window expired',
                            CAST(:metadata AS JSONB),
                            NOW()
                        )
                    SQL,
                    [
                        'order_id' => $vendorOrder['order_id'],
                        'vendor_order_id' => $vendorOrderId,
                        'metadata' => json_encode([
                            'completion_source' => 'automatic_acceptance',
                            'active_dispute' => $hasActiveDispute,
                            'refund_hold' => $hasRefundHold,
                            'payable_status_before' => $payable['status'],
                        ], JSON_THROW_ON_ERROR),
                    ]
                );

                $completedCount++;
            }

            $this->db->commit();

            return $completedCount;

        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }
}