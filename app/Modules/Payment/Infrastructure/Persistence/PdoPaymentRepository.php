<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use App\Modules\Notification\Domain\Contracts\OutboxEventPublisherInterface;
use App\Shared\Enums\EventTypes;
use InvalidArgumentException;
use Phalcon\Events\Event;
use Throwable;

final class PdoPaymentRepository implements PaymentRepository
{
    public function __construct(
        private readonly AdapterInterface $db,
        private readonly LedgerPostingService $ledgerPostingService,
        private readonly OutboxEventPublisherInterface $outboxEventPublisher,
    ) {
    }

    public function findCustomerOrder(
        string $customerId,
        string $orderId
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                o.id,
                o.order_number,
                o.customer_id,
                o.status,
                o.payment_status,
                o.currency,
                o.total_minor,
                u.email AS customer_email
            FROM public.orders o
            INNER JOIN public.users u
                ON u.id = o.customer_id
            WHERE o.id = :order_id
              AND o.customer_id = :customer_id
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'order_id' => $orderId,
            'customer_id' => $customerId,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function findActivePaymentForOrder(
        string $orderId
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                id,
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status,
                authorization_url,
                access_code,
                provider_transaction_id,
                failure_reason,
                initialized_at,
                verified_at,
                created_at,
                updated_at
            FROM public.payments
            WHERE order_id = :order_id
              AND status IN ('pending', 'initialized')
            ORDER BY created_at DESC
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'order_id' => $orderId,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function createPendingPayment(
        string $orderId,
        string $provider,
        string $reference,
        int $amountMinor,
        string $currency
    ): array {
        $sql = <<<'SQL'
            INSERT INTO public.payments (
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status
            ) VALUES (
                :order_id,
                :provider,
                :reference,
                :amount_minor,
                :currency,
                'pending'
            )
            RETURNING
                id,
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status,
                created_at
        SQL;

        $result = $this->db->query($sql, [
            'order_id' => $orderId,
            'provider' => $provider,
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($currency),
        ]);

        $payment = $result->fetch();

        if ($payment === false) {
            throw new RuntimeException(
                'Unable to create payment record.'
            );
        }

        return $payment;
    }

    public function markPaymentInitialized(
        string $reference,
        string $authorizationUrl,
        ?string $accessCode,
        array $providerResponse
    ): void {
        $sql = <<<'SQL'
            UPDATE public.payments
            SET
                status = 'initialized',
                authorization_url = :authorization_url,
                access_code = :access_code,
                provider_response = CAST(:provider_response AS JSONB),
                initialized_at = NOW(),
                updated_at = NOW()
            WHERE reference = :reference
              AND status = 'pending'
        SQL;

        $this->db->execute($sql, [
            'authorization_url' => $authorizationUrl,
            'access_code' => $accessCode,
            'provider_response' => $this->encodeJson($providerResponse),
            'reference' => $reference,
        ]);

        $this->assertPaymentUpdated($reference, 'initialized');
    }

    public function markPaymentInitializationFailed(
        string $reference,
        string $failureReason,
        array $providerResponse = []
    ): void {
        $sql = <<<'SQL'
            UPDATE public.payments
            SET
                status = 'failed',
                failure_reason = :failure_reason,
                provider_response = CAST(:provider_response AS JSONB),
                failed_at = NOW(),
                updated_at = NOW()
            WHERE reference = :reference
              AND status = 'pending'
        SQL;

        $this->db->execute($sql, [
            'failure_reason' => mb_substr($failureReason, 0, 1000),
            'provider_response' => $this->encodeJson($providerResponse),
            'reference' => $reference,
        ]);

        $this->assertPaymentUpdated($reference, 'failed');
    }

    public function findByReference(string $reference): ?array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status,
                authorization_url,
                access_code,
                provider_transaction_id,
                failure_reason,
                initialized_at,
                verified_at,
                failed_at,
                provider_response,
                created_at,
                updated_at
            FROM public.payments
            WHERE reference = :reference
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'reference' => $reference,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function findCustomerPayment(
        string $customerId,
        string $reference
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.order_id,
                p.provider,
                p.reference,
                p.amount_minor,
                p.currency,
                p.status,
                p.authorization_url,
                p.access_code,
                p.provider_transaction_id,
                p.failure_reason,
                p.initialized_at,
                p.verified_at,
                p.failed_at,
                p.created_at,
                p.updated_at
            FROM public.payments p
            INNER JOIN public.orders o
                ON o.id = p.order_id
            WHERE p.reference = :reference
            AND o.customer_id = :customer_id
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'reference' => $reference,
            'customer_id' => $customerId,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function confirmSuccessfulPayment(
        string $reference,
        string $providerTransactionId,
        array $providerResponse
    ): array {
        if ($reference === '' || $providerTransactionId === '') {
            throw new RuntimeException(
                'Payment reference and provider transaction ID are required.'
            );
        }

        $this->db->begin();

        try {
            // Lock the payment first to serialize duplicate confirmations.
            $result = $this->db->query(
                <<<'SQL'
                SELECT *
                FROM public.payments
                WHERE reference = :reference
                FOR UPDATE
            SQL,
                ['reference' => $reference]
            );

            $payment = $result->fetch();

            if ($payment === false) {
                throw new RuntimeException('Payment not found.');
            }

            // Idempotency: an already settled payment must not settle twice.
            if ($payment['status'] === 'success') {
                $this->db->commit();

                return $payment;
            }

            if (
                !in_array(
                    $payment['status'],
                    ['pending', 'initialized', 'failed'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Payment cannot be confirmed from its current status.'
                );
            }

            // Lock the parent order.
            $result = $this->db->query(
                <<<'SQL'
                SELECT id, status, payment_status
                FROM public.orders
                WHERE id = :order_id
                FOR UPDATE
            SQL,
                ['order_id' => $payment['order_id']]
            );

            $order = $result->fetch();

            if ($order === false) {
                throw new RuntimeException(
                    'The order associated with this payment could not be found.'
                );
            }

            if (
                $order['status'] !== 'pending_payment'
                || !in_array(
                    $order['payment_status'],
                    ['pending', 'failed'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'The order cannot be settled from its current status.'
                );
            }

            // Lock all outstanding inventory reservations for this order.
            // Lock all inventory reservations for this order.
            $result = $this->db->query(
                <<<'SQL'
        SELECT
            id,
            order_item_id,
            variant_id,
            quantity,
            status,
            (expires_at <= NOW()) AS is_expired
        FROM public.inventory_reservations
        WHERE order_id = :order_id
        ORDER BY variant_id
        FOR UPDATE
    SQL,
                ['order_id' => $payment['order_id']]
            );

            $reservations = $result->fetchAll();

            if ($reservations === []) {
                throw new RuntimeException(
                    'No inventory reservations were found. Payment requires reconciliation.'
                );
            }

            // Validate every reservation before modifying inventory.
            // foreach ($reservations as $reservation) {
            //     if (
            //         $reservation['status'] !== 'reserved' || in_array(
            //             $reservation['is_expired'],
            //             [true, 1, '1', 't', 'true'],
            //             true
            //         )
            //     ) {
            //         throw new RuntimeException(
            //             'An inventory reservation is no longer valid. '
            //             . 'Payment requires reconciliation.'
            //         );
            //     }
            // }
            foreach ($reservations as $reservation) {
                if ($reservation['status'] !== 'reserved') {
                    throw new RuntimeException(
                        'Inventory reservation is no longer available. '
                        . 'Payment requires reconciliation.'
                    );
                }
            }

            foreach ($reservations as $reservation) {
                $quantity = (int) $reservation['quantity'];
                $variantId = $reservation['variant_id'];

                if ($quantity <= 0) {
                    throw new RuntimeException(
                        'An invalid inventory reservation quantity was found.'
                    );
                }

                // Lock the inventory row before changing reserved quantity.
                $result = $this->db->query(
                    <<<'SQL'
                    SELECT available_qty, reserved_qty
                    FROM public.inventory
                    WHERE variant_id = :variant_id
                    FOR UPDATE
                SQL,
                    ['variant_id' => $variantId]
                );

                $inventory = $result->fetch();

                if ($inventory === false) {
                    throw new RuntimeException(
                        'Inventory record not found for variant: ' . $variantId
                    );
                }

                if ((int) $inventory['reserved_qty'] < $quantity) {
                    throw new RuntimeException(
                        'Reserved inventory quantity is insufficient for payment settlement. '
                        . 'Payment requires reconciliation.'
                    );
                }

                $availableBefore = (int) $inventory['available_qty'];
                $reservedBefore = (int) $inventory['reserved_qty'];

                if ($reservedBefore < $quantity) {
                    throw new RuntimeException(
                        'Reserved inventory is insufficient for variant: '
                        . $variantId
                    );
                }

                $reservedAfter = $reservedBefore - $quantity;

                $this->db->execute(
                    <<<'SQL'
                    UPDATE public.inventory
                    SET
                        reserved_qty = :reserved_after,
                        updated_at = NOW()
                    WHERE variant_id = :variant_id
                SQL,
                    [
                        'reserved_after' => $reservedAfter,
                        'variant_id' => $variantId,
                    ]
                );

                // Record the actual sale movement.
                $this->db->execute(
                    <<<'SQL'
                    INSERT INTO public.inventory_movements (
                        variant_id,
                        movement_type,
                        quantity_delta,
                        available_before,
                        available_after,
                        reserved_before,
                        reserved_after,
                        reason,
                        reference_type,
                        reference_id,
                        actor_user_id
                    ) VALUES (
                        :variant_id,
                        'sale',
                        :quantity_delta,
                        :available_before,
                        :available_after,
                        :reserved_before,
                        :reserved_after,
                        :reason,
                        'order',
                        :order_id,
                        NULL
                    )
                SQL,
                    [
                        'variant_id' => $variantId,
                        'quantity_delta' => -$quantity,
                        'available_before' => $availableBefore,
                        'available_after' => $availableBefore,
                        'reserved_before' => $reservedBefore,
                        'reserved_after' => $reservedAfter,
                        'reason' => 'Inventory committed after successful payment.',
                        'order_id' => $payment['order_id'],
                    ]
                );

                // Commit the reservation.
                $this->db->execute(
                    <<<'SQL'
                        UPDATE public.inventory_reservations
                        SET
                            status = 'committed',
                            committed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = :reservation_id
                        AND status = 'reserved'
                    SQL,
                    ['reservation_id' => $reservation['id']]
                );
            }

            // Mark the payment successful.
            $this->db->execute(
                <<<'SQL'
                UPDATE public.payments
                SET
                    status = 'success',
                    provider_transaction_id = :transaction_id,
                    provider_response = CAST(:provider_response AS JSONB),
                    verified_at = NOW(),
                    failure_reason = NULL,
                    updated_at = NOW()
                WHERE id = :payment_id
                  AND status IN ('pending', 'initialized', 'failed')
            SQL,
                [
                    'transaction_id' => $providerTransactionId,
                    'provider_response' => $this->encodeJson($providerResponse),
                    'payment_id' => $payment['id'],
                ]
            );

            // Update the parent order.
            $this->db->execute(
                <<<'SQL'
                UPDATE public.orders
                SET
                    status = 'paid',
                    payment_status = 'paid',
                    paid_at = NOW(),
                    updated_at = NOW()
                WHERE id = :order_id
                  AND status = 'pending_payment'
                  AND payment_status IN ('pending', 'failed')
            SQL,
                ['order_id' => $payment['order_id']]
            );

            // Record the order status transition.
            $this->db->execute(
                <<<'SQL'
                INSERT INTO public.order_status_history (
                    order_id,
                    vendor_order_id,
                    old_status,
                    new_status,
                    changed_by,
                    reason,
                    metadata
                ) VALUES (
                    :order_id,
                    NULL,
                    :old_status,
                    'paid',
                    NULL,
                    'Payment successfully verified.',
                    CAST(:metadata AS JSONB)
                )
            SQL,
                [
                    'order_id' => $payment['order_id'],
                    'old_status' => $order['status'],
                    'metadata' => $this->encodeJson([
                        'payment_id' => $payment['id'],
                        'payment_reference' => $reference,
                        'provider' => $payment['provider'],
                    ]),
                ]
            );

            // Retrieve and lock vendor orders with their commission overrides.
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        vo.id,
                        vo.order_id,
                        vo.store_id,
                        vo.order_number,
                        vo.status,
                        vo.subtotal_minor,
                        vo.shipping_minor,
                        vo.tax_minor,
                        vo.discount_minor,
                        vo.total_minor,
                        vo.currency,
                        s.commission_rate_bps
                    FROM public.vendor_orders vo
                    INNER JOIN public.stores s
                        ON s.id = vo.store_id
                    WHERE vo.order_id = :order_id
                    ORDER BY vo.id
                    FOR UPDATE OF vo
                    SQL,
                [
                    'order_id' => $payment['order_id'],
                ]
            );

            $vendorOrders = $result->fetchAll();

            if ($vendorOrders === []) {
                throw new RuntimeException(
                    'No vendor orders were found for this payment.'
                );
            }

            $this->ledgerPostingService->postPayment($payment, $vendorOrders);

            $this->outboxEventPublisher->publish(
                eventType: EventTypes::PAYMENT_SUCCEEDED->value,
                aggregateType: 'payment',
                aggregateId: (string) $payment['id'],
                idempotencyKey: EventTypes::PAYMENT_SUCCEEDED->value .':'. $payment['id'],
                payload: [
                    'payment_id' => (string) $payment['id'],
                    'order_id' => (string) $payment['order_id'],
                    'reference' => (string) $payment['reference'],
                    'provider' => (string) $payment['provider'],
                    'provider_transaction_id' => $providerTransactionId,
                    'amount_minor' => (int) $payment['amount_minor'],
                    'currency' => (string) $payment['currency'],
                ],
            );

            $this->db->commit();

            return $this->findByReference($reference)
                ?? throw new RuntimeException(
                    'Confirmed payment could not be retrieved.'
                );

        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }


    public function markPaymentFailed(
        string $reference,
        string $failureReason,
        array $providerResponse = [],
    ): array {
        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException(
                'Payment reference is required.'
            );
        }

        $this->db->begin();

        try {
            $result = $this->db->query(
                <<<'SQL'
                SELECT
                    id,
                    order_id,
                    provider,
                    reference,
                    amount_minor,
                    currency,
                    status
                FROM public.payments
                WHERE reference = :reference
                FOR UPDATE
            SQL,
                [
                    'reference' => $reference,
                ],
            );

            $payment = $result->fetch();

            if (!$payment) {
                throw new RuntimeException(
                    'Payment not found.'
                );
            }

            // Only pending or initialized payments can transition to failed.
            // Successful and already-failed payments remain unchanged.
            if (
                !in_array(
                    $payment['status'],
                    ['pending', 'initialized'],
                    true,
                )
            ) {
                $currentPayment = $this->findByReference($reference);

                if ($currentPayment === null) {
                    throw new RuntimeException(
                        'Payment could not be retrieved.'
                    );
                }

                $this->db->commit();

                return $currentPayment;
            }

            $failureReason = mb_substr($failureReason, 0, 1000);

            $encodedProviderResponse = $this->encodeJson(
                $providerResponse
            );

            $result = $this->db->query(
                <<<'SQL'
                UPDATE public.payments
                SET
                    status = 'failed',
                    failure_reason = :failure_reason,
                    provider_response = CAST(:provider_response AS JSONB),
                    failed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :payment_id
                  AND status IN ('pending', 'initialized')
                RETURNING id
            SQL,
                [
                    'failure_reason' => $failureReason,
                    'provider_response' => $encodedProviderResponse,
                    'payment_id' => (string) $payment['id'],
                ],
            );

            $updatedPayment = $result->fetch();

            if (!$updatedPayment) {
                throw new RuntimeException(
                    'Payment failure update could not be confirmed.'
                );
            }

            $this->outboxEventPublisher->publish(
                eventType: EventTypes::PAYMENT_FAILED->value,
                aggregateType: 'payment',
                aggregateId: (string) $payment['id'],
                idempotencyKey: EventTypes::PAYMENT_FAILED->value . ':' . $payment['id'],
                payload: [
                    'payment_id' => (string) $payment['id'],
                    'order_id' => (string) $payment['order_id'],
                    'reference' => (string) $payment['reference'],
                    'provider' => (string) $payment['provider'],
                    'amount_minor' => (int) $payment['amount_minor'],
                    'currency' => (string) $payment['currency'],
                    'failure_reason' => $failureReason,
                ],
            );

            $updatedPayment = $this->findByReference($reference);

            if ($updatedPayment === null) {
                throw new RuntimeException(
                    'Failed payment could not be retrieved.'
                );
            }

            $this->db->commit();

            return $updatedPayment;
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }


    /**
     * Prepare a vendor-order refund and persist it before contacting the provider.
     *
     * A null amount means the full remaining refundable balance of the vendor order,
     * capped by the remaining refundable balance of the original payment.
     *
     * @return array<string, mixed>
     */
    public function prepareRefund(
        string $paymentReference,
        string $vendorOrderId,
        ?int $amountMinor,
        string $providerReference,
        string $idempotencyKey,
        string $reason,
        ?string $requestedBy
    ): array {
        $paymentReference = trim($paymentReference);
        $vendorOrderId = trim($vendorOrderId);
        $providerReference = trim($providerReference);
        $idempotencyKey = trim($idempotencyKey);
        $reason = trim($reason);

        if (
            $paymentReference === ''
            || $vendorOrderId === ''
            || $providerReference === ''
            || $idempotencyKey === ''
            || $reason === ''
        ) {
            throw new InvalidArgumentException(
                'Payment reference, vendor order, provider reference, '
                . 'idempotency key and refund reason are required.'
            );
        }

        if ($amountMinor !== null && $amountMinor <= 0) {
            throw new InvalidArgumentException(
                'Refund amount must be greater than zero.'
            );
        }

        if (strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException(
                'Refund idempotency key cannot exceed 128 characters.'
            );
        }

        if (strlen($providerReference) > 150) {
            throw new InvalidArgumentException(
                'Provider refund reference cannot exceed 150 characters.'
            );
        }

        $this->db->begin();

        try {
            /*
             * Lock the payment first. This serializes refund preparation
             * for all vendor orders belonging to the same payment.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        id,
                        order_id,
                        provider,
                        reference,
                        provider_transaction_id,
                        amount_minor,
                        currency,
                        status
                    FROM public.payments
                    WHERE reference = :reference
                    FOR UPDATE
                SQL,
                [
                    'reference' => $paymentReference,
                ]
            );

            $payment = $result->fetch();

            if ($payment === false) {
                throw new RuntimeException(
                    'The original payment could not be found.'
                );
            }

            if ($payment['status'] !== 'success') {
                throw new RuntimeException(
                    'Only successfully completed payments can be refunded.'
                );
            }

            if (strtolower((string) $payment['provider']) !== 'paystack') {
                throw new RuntimeException(
                    'Refunds are currently supported only for Paystack payments.'
                );
            }

            if (
                !is_string($payment['provider_transaction_id'])
                || trim($payment['provider_transaction_id']) === ''
            ) {
                throw new RuntimeException(
                    'The original provider transaction ID is missing.'
                );
            }

            /*
             * Idempotency check.
             *
             * Return the existing refund when the same request is retried.
             * Reject reuse of the key for a different refund request.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        r.*,
                        p.reference AS payment_reference,
                        p.provider_transaction_id,
                        p.provider AS payment_provider
                    FROM public.refunds r
                    INNER JOIN public.payments p
                        ON p.id = r.payment_id
                    WHERE r.idempotency_key = :idempotency_key
                    LIMIT 1
                SQL,
                [
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            $existingRefund = $result->fetch();

            if ($existingRefund !== false) {
                $sameRequest =
                    (string) $existingRefund['payment_id'] === (string) $payment['id']
                    && (string) $existingRefund['vendor_order_id'] === $vendorOrderId
                    && (string) $existingRefund['provider_reference'] === $providerReference
                    && (string) $existingRefund['reason'] === $reason
                    && (
                        $existingRefund['requested_by'] === null
                        ? $requestedBy === null
                        : (string) $existingRefund['requested_by'] === $requestedBy
                    )
                    && (
                        $amountMinor === null
                        || (int) $existingRefund['amount_minor'] === $amountMinor
                    );

                if (!$sameRequest) {
                    throw new RuntimeException(
                        'The refund idempotency key has already been used '
                        . 'for a different request.'
                    );
                }

                $this->db->commit();

                return $existingRefund;
            }

            /*
             * Lock and validate the vendor order.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        id,
                        order_id,
                        total_minor,
                        currency,
                        status
                    FROM public.vendor_orders
                    WHERE id = :vendor_order_id
                    FOR UPDATE
                SQL,
                [
                    'vendor_order_id' => $vendorOrderId,
                ]
            );

            $vendorOrder = $result->fetch();

            if ($vendorOrder === false) {
                throw new RuntimeException(
                    'The vendor order could not be found.'
                );
            }

            if (
                (string) $vendorOrder['order_id']
                !== (string) $payment['order_id']
            ) {
                throw new RuntimeException(
                    'The vendor order does not belong to the original payment.'
                );
            }

            if (
                strtoupper((string) $vendorOrder['currency'])
                !== strtoupper((string) $payment['currency'])
            ) {
                throw new RuntimeException(
                    'The vendor order currency does not match the payment currency.'
                );
            }

            if (
                in_array(
                    $vendorOrder['status'],
                    ['cancelled', 'refunded'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'This vendor order is not eligible for a refund.'
                );
            }

            /*
             * Parent-order refunds cannot safely be allocated to individual
             * vendor orders without an explicit allocation policy.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT COUNT(*) AS refund_count
                    FROM public.refunds
                    WHERE payment_id = :payment_id
                    AND vendor_order_id IS NULL
                    AND status IN ('pending', 'processing', 'success')
                SQL,
                [
                    'payment_id' => $payment['id'],
                ]
            );

            $parentRefunds = $result->fetch();

            if (
                $parentRefunds !== false
                && (int) $parentRefunds['refund_count'] > 0
            ) {
                throw new RuntimeException(
                    'A parent-order refund already exists. '
                    . 'Vendor-specific refunds require refund allocation reconciliation.'
                );
            }

            /*
             * Calculate refunds already reserved or completed for this payment
             * and this vendor order. Failed and cancelled refunds do not consume
             * the refundable balance.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        COALESCE(
                            SUM(amount_minor),
                            0
                        ) AS refunded_minor
                    FROM public.refunds
                    WHERE payment_id = :payment_id
                    AND status IN ('pending', 'processing', 'success')
                SQL,
                [
                    'payment_id' => $payment['id'],
                ]
            );

            $paymentRefundTotals = $result->fetch();

            $paymentRefundedMinor = (int) (
                $paymentRefundTotals['refunded_minor'] ?? 0
            );

            $paymentRemainingMinor =
                (int) $payment['amount_minor'] - $paymentRefundedMinor;

            if ($paymentRemainingMinor <= 0) {
                throw new RuntimeException(
                    'The original payment has no remaining refundable balance.'
                );
            }

            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        COALESCE(
                            SUM(amount_minor),
                            0
                        ) AS refunded_minor
                    FROM public.refunds
                    WHERE payment_id = :payment_id
                    AND vendor_order_id = :vendor_order_id
                    AND status IN ('pending', 'processing', 'success')
                SQL,
                [
                    'payment_id' => $payment['id'],
                    'vendor_order_id' => $vendorOrderId,
                ]
            );

            $vendorRefundTotals = $result->fetch();

            $vendorRefundedMinor = (int) (
                $vendorRefundTotals['refunded_minor'] ?? 0
            );

            $vendorRemainingMinor =
                (int) $vendorOrder['total_minor'] - $vendorRefundedMinor;

            if ($vendorRemainingMinor <= 0) {
                throw new RuntimeException(
                    'The vendor order has no remaining refundable balance.'
                );
            }

            $remainingRefundableMinor = min(
                $paymentRemainingMinor,
                $vendorRemainingMinor
            );

            $refundAmountMinor = $amountMinor ?? $remainingRefundableMinor;

            if ($refundAmountMinor > $remainingRefundableMinor) {
                throw new RuntimeException(
                    sprintf(
                        'Refund amount exceeds the remaining refundable balance of %d minor units.',
                        $remainingRefundableMinor
                    )
                );
            }

            /*
             * Create the durable refund record before contacting Paystack.
             */
            $result = $this->db->query(
                <<<'SQL'
                    INSERT INTO public.refunds (
                        order_id,
                        payment_id,
                        vendor_order_id,
                        provider,
                        idempotency_key,
                        provider_reference,
                        amount_minor,
                        currency,
                        status,
                        reason,
                        requested_by,
                        requested_at,
                        created_at,
                        updated_at
                    ) VALUES (
                        :order_id,
                        :payment_id,
                        :vendor_order_id,
                        :provider,
                        :idempotency_key,
                        :provider_reference,
                        :amount_minor,
                        :currency,
                        'pending',
                        :reason,
                        :requested_by,
                        NOW(),
                        NOW(),
                        NOW()
                    )
                    RETURNING *
                SQL,
                [
                    'order_id' => $payment['order_id'],
                    'payment_id' => $payment['id'],
                    'vendor_order_id' => $vendorOrderId,
                    'provider' => $payment['provider'],
                    'idempotency_key' => $idempotencyKey,
                    'provider_reference' => $providerReference,
                    'amount_minor' => $refundAmountMinor,
                    'currency' => strtoupper((string) $payment['currency']),
                    'reason' => mb_substr($reason, 0, 2000),
                    'requested_by' => $requestedBy,
                ]
            );

            $refund = $result->fetch();

            if ($refund === false) {
                throw new RuntimeException(
                    'The refund record could not be created.'
                );
            }

            /*
             * Include the original transaction reference needed by Paystack.
             */
            $refund['payment_reference'] = $payment['reference'];
            $refund['provider_transaction_id'] =
                $payment['provider_transaction_id'];
            $refund['payment_provider'] = $payment['provider'];

            $this->db->commit();

            return $refund;
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    /**
     * Mark a prepared refund as being submitted to the provider.
     */
    /**
     * Mark a prepared refund as being submitted to the provider.
     */
    /**
     * Atomically claim a pending refund for provider submission.
     *
     * Returns true only for the request that changes pending to processing.
     */
    public function markRefundProcessing(string $refundId): bool {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.refunds
                SET
                    status = 'processing',
                    updated_at = NOW()
                WHERE id = :refund_id
                AND status = 'pending'
                RETURNING id
            SQL,
            [
                'refund_id' => $refundId,
            ]
        );

        return $result->fetch() !== false;
    }

    /**
     * Find a refund using its application idempotency key.
     *
     * @return array<string, mixed>|null
     */
    public function findRefundByIdempotencyKey(
        string $idempotencyKey
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.refunds
                WHERE idempotency_key = :idempotency_key
                LIMIT 1
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
            ]
        );

        $refund = $result->fetch();

        return $refund !== false ? $refund : null;
    }

    /**
     * Persist the provider's refund response.
     *
     * Paystack's needs-attention status is mapped to processing because
     * the current refunds table constraint does not include needs_attention.
     *
     * @param array<string, mixed> $providerResponse
     * @return array<string, mixed>
     */
    public function updateRefundProviderResult(
        string $refundId,
        string $providerReference,
        string $status,
        array $providerResponse
    ): array {
        $refundId = trim($refundId);
        $providerReference = trim($providerReference);
        $status = strtolower(trim($status));

        if ($refundId === '' || $providerReference === '') {
            throw new InvalidArgumentException(
                'Refund ID and provider reference are required.'
            );
        }

        $status = match ($status) {
            'processed' => 'success',
            'needs-attention',
            'needs_attention' => 'processing',
            default => $status,
        };

        $allowedStatuses = [
            'pending',
            'processing',
            'success',
            'failed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                'Unsupported provider refund status.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.refunds
                SET
                    provider_reference = :provider_reference,
                    status = :status,
                    provider_response = CAST(:provider_response AS JSONB),
                    processed_at = CASE
                        WHEN :is_terminal = TRUE THEN COALESCE(processed_at, NOW())
                        ELSE processed_at
                    END,
                    confirmed_at = CASE
                        WHEN :is_success = TRUE THEN COALESCE(confirmed_at, NOW())
                        ELSE confirmed_at
                    END,
                    updated_at = NOW()
                WHERE id = :refund_id
                AND status <> 'success'
                RETURNING *
            SQL,
            [
                'provider_reference' => $providerReference,
                'status' => $status,
                'provider_response' => $this->encodeJson($providerResponse),
                'is_terminal' => in_array(
                    $status,
                    ['success', 'failed', 'cancelled'],
                    true
                ),
                'is_success' => $status === 'success',
                'refund_id' => $refundId,
            ]
        );

        $refund = $result->fetch();

        if ($refund !== false) {
            return $refund;
        }

        /*
         * A successful refund is immutable here: a late pending or failed
         * notification must not downgrade it.
         */
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.refunds
                WHERE id = :refund_id
                LIMIT 1
            SQL,
            [
                'refund_id' => $refundId,
            ]
        );

        $existingRefund = $result->fetch();

        if ($existingRefund === false) {
            throw new RuntimeException(
                'The refund record could not be found.'
            );
        }

        if ($existingRefund['status'] === 'success') {
            return $existingRefund;
        }

        throw new RuntimeException(
            'The refund provider response could not be persisted.'
        );
    }

    /**
     * Retrieve the idempotency key belonging to a refund.
     */
    private function findRefundIdempotencyKey(
        string $refundId
    ): string {
        $result = $this->db->query(
            <<<'SQL'
        SELECT idempotency_key
        FROM public.refunds
        WHERE id = :refund_id
        LIMIT 1
        SQL,
            [
                'refund_id' => $refundId,
            ]
        );

        $row = $result->fetch();

        if ($row === false) {
            throw new RuntimeException(
                'The refund record could not be found.'
            );
        }

        return (string) $row['idempotency_key'];
    }

    public function findSuccessfulPaymentReferenceForVendorOrder(
        string $vendorOrderId,
    ): ?string {
        $result = $this->db->query(
            <<<'SQL'
                SELECT p.reference
                FROM public.vendor_orders vo
                INNER JOIN public.payments p
                    ON p.order_id = vo.order_id
                WHERE vo.id = :vendor_order_id
                AND p.status = 'success'
                ORDER BY p.created_at DESC
                LIMIT 1
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
            ]
        );

        $row = $result->fetch();

        if ($row === false) {
            return null;
        }

        return (string) $row['reference'];
    }

    public function findRefundForWebhook(
        ?string $providerReference,
        string $transactionReference,
        int $amountMinor,
        string $currency
    ): ?array {
        $transactionReference = trim($transactionReference);
        $providerReference = $providerReference !== null
            ? trim($providerReference)
            : null;
        $currency = strtoupper(trim($currency));

        if (
            $transactionReference === ''
            || $amountMinor <= 0
            || $currency === ''
        ) {
            throw new InvalidArgumentException(
                'Invalid refund webhook matching parameters.'
            );
        }

        if ($providerReference !== null && $providerReference !== '') {
            $result = $this->db->query(
                <<<'SQL'
                    SELECT r.*
                    FROM public.refunds r
                    INNER JOIN public.payments p
                        ON p.id = r.payment_id
                    WHERE r.provider = 'paystack'
                    AND r.provider_reference = :provider_reference
                    AND p.provider_transaction_id = :transaction_reference
                    AND r.amount_minor = :amount_minor
                    AND UPPER(r.currency) = :currency
                    LIMIT 2
                SQL,
                [
                    'provider_reference' => $providerReference,
                    'transaction_reference' => $transactionReference,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                ]
            );
        } else {
            /*
             * Paystack may omit refund_reference in early refund events.
             * In that case, only accept an unambiguous match.
             */
            $result = $this->db->query(
                <<<'SQL'
                    SELECT r.*
                    FROM public.refunds r
                    INNER JOIN public.payments p
                        ON p.id = r.payment_id
                    WHERE r.provider = 'paystack'
                    AND p.provider_transaction_id = :transaction_reference
                    AND r.amount_minor = :amount_minor
                    AND UPPER(r.currency) = :currency
                    ORDER BY r.created_at DESC
                    LIMIT 2
                SQL,
                [
                    'transaction_reference' => $transactionReference,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                ]
            );
        }

        $rows = $result->fetchAll();

        if (count($rows) === 0) {
            return null;
        }

        if (count($rows) > 1) {
            throw new RuntimeException(
                'The Paystack refund webhook matches multiple internal refunds.'
            );
        }

        return $rows[0];
    }

    public function updateRefundWebhookStatus(
        string $refundId,
        ?string $providerReference,
        string $status,
        array $providerResponse
    ): array {
        $allowedStatuses = [
            'pending',
            'processing',
            'needs_attention',
            'failed',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                'Unsupported non-success refund webhook status.'
            );
        }

        $this->db->begin();

        try {
            $result = $this->db->query(
                <<<'SQL'
            SELECT *
            FROM public.refunds
            WHERE id = :refund_id
            FOR UPDATE
            SQL,
                [
                    'refund_id' => $refundId,
                ]
            );

            $refund = $result->fetch();

            if ($refund === false) {
                throw new RuntimeException('Refund record was not found.');
            }

            $existingReference = $refund['provider_reference'] !== null
                ? (string) $refund['provider_reference']
                : null;

            if (
                $providerReference !== null
                && $existingReference !== null
                && $existingReference !== $providerReference
            ) {
                throw new RuntimeException(
                    'Paystack refund reference does not match the stored refund reference.'
                );
            }

            $currentStatus = (string) $refund['status'];

            // Successful and cancelled refunds are immutable here.
            // Failed refunds require manual reconciliation before any retry.
            if (in_array($currentStatus, ['success', 'cancelled', 'failed'], true)) {
                $this->db->commit();

                return $refund;
            }

            $allowedTransitions = [
                'pending' => ['pending', 'processing', 'needs_attention', 'failed'],
                'processing' => ['processing', 'needs_attention', 'failed'],
                'needs_attention' => ['needs_attention', 'processing', 'failed'],
            ];

            if (
                !in_array(
                    $status,
                    $allowedTransitions[$currentStatus] ?? [],
                    true
                )
            ) {
                // Ignore stale/out-of-order provider events.
                $this->db->commit();

                return $refund;
            }

            $result = $this->db->query(
                <<<'SQL'
            UPDATE public.refunds
            SET
                provider_reference = COALESCE(provider_reference, :provider_reference),
                status = :status,
                provider_response = CAST(:provider_response AS JSONB),
                updated_at = NOW()
            WHERE id = :refund_id
            RETURNING *
            SQL,
                [
                    'refund_id' => $refundId,
                    'provider_reference' => $providerReference,
                    'status' => $status,
                    'provider_response' => json_encode(
                        $providerResponse,
                        JSON_THROW_ON_ERROR
                    ),
                ]
            );

            $updatedRefund = $result->fetch();

            if ($updatedRefund === false) {
                throw new RuntimeException('Failed to update refund webhook status.');
            }

            $this->db->commit();

            return $updatedRefund;
        } catch (Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }

    public function applyRefundWebhookUpdate(
        string $refundId,
        ?string $providerReference,
        string $status,
        array $providerResponse
    ): array {
        $allowedStatuses = [
            'pending',
            'processing',
            'needs_attention',
            'failed',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                'Unsupported non-settlement refund status.'
            );
        }

        $providerReference = $providerReference !== null
            ? trim($providerReference)
            : null;

        $encodedResponse = json_encode(
            $providerResponse,
            JSON_THROW_ON_ERROR
        );

        $this->db->begin();

        try {
            $result = $this->db->query(
                <<<'SQL'
            SELECT *
            FROM public.refunds
            WHERE id = :refund_id
            FOR UPDATE
            SQL,
                [
                    'refund_id' => $refundId,
                ]
            );

            $refund = $result->fetch();

            if ($refund === false) {
                throw new RuntimeException(
                    'The internal refund record could not be found.'
                );
            }

            $currentStatus = (string) $refund['status'];

            /*
             * Successful and cancelled refunds are terminal here.
             * Successful refunds are handled by the financial settlement
             * workflow, not this status-only update.
             */
            if (in_array($currentStatus, ['success', 'cancelled'], true)) {
                $this->db->commit();

                return $refund;
            }

            /*
             * Failed is terminal for this webhook status-only workflow.
             * A later contradictory provider event requires reconciliation.
             */
            if (
                $currentStatus === 'failed'
                && $status !== 'failed'
            ) {
                $this->db->commit();

                return $refund;
            }

            /*
             * Do not allow an older event to downgrade the current state.
             */
            $rank = [
                'pending' => 0,
                'processing' => 1,
                'needs_attention' => 2,
                'failed' => 3,
            ];

            if ($rank[$status] < $rank[$currentStatus]) {
                $this->db->commit();

                return $refund;
            }

            $result = $this->db->query(
                <<<'SQL'
            UPDATE public.refunds
            SET
                status = :status,
                provider_reference = COALESCE(
                    provider_reference,
                    :provider_reference
                ),
                provider_response = CAST(:provider_response AS JSONB),
                updated_at = NOW()
            WHERE id = :refund_id
            RETURNING *
            SQL,
                [
                    'status' => $status,
                    'provider_reference' => $providerReference,
                    'provider_response' => $encodedResponse,
                    'refund_id' => $refundId,
                ]
            );

            $updatedRefund = $result->fetch();

            if ($updatedRefund === false) {
                throw new RuntimeException(
                    'The refund webhook update could not be persisted.'
                );
            }

            $this->db->commit();

            return $updatedRefund;
        } catch (Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }

    private function assertPaymentUpdated(
        string $reference,
        string $expectedStatus
    ): void {
        $payment = $this->findByReference($reference);

        if (
            $payment === null
            || $payment['status'] !== $expectedStatus
        ) {
            throw new RuntimeException(
                sprintf(
                    'Payment %s could not be updated to %s.',
                    $reference,
                    $expectedStatus
                )
            );
        }
    }

    /**
     * @throws JsonException
     */
    private function encodeJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }
}