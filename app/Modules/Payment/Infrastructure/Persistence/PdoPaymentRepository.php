<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoPaymentRepository implements PaymentRepository
{
    public function __construct(
        private readonly AdapterInterface $db
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
            foreach ($reservations as $reservation) {
                if (
                    $reservation['status'] !== 'reserved' || in_array(
                        $reservation['is_expired'],
                        [true, 1, '1', 't', 'true'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'An inventory reservation is no longer valid. '
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

            $this->db->commit();

            return $this->findByReference($reference)
                ?? throw new RuntimeException(
                    'Confirmed payment could not be retrieved.'
                );

        } catch (\Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    public function markPaymentFailed(
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
          AND status IN ('pending', 'initialized')
    SQL;

        $this->db->execute($sql, [
            'failure_reason' => mb_substr($failureReason, 0, 1000),
            'provider_response' => $this->encodeJson($providerResponse),
            'reference' => $reference,
        ]);

        // A payment already marked successful must never be downgraded.
        $payment = $this->findByReference($reference);

        if ($payment === null) {
            throw new RuntimeException(
                'Payment could not be found after attempting to mark it failed.'
            );
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