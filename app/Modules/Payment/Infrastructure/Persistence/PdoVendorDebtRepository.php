<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Repositories\VendorDebtRepositoryInterface;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoVendorDebtRepository implements VendorDebtRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function recordRefundDebt(
        string $storeId,
        string $refundId,
        string $vendorOrderId,
        string $currency,
        int $amountMinor,
    ): int {
        $storeId = trim($storeId);
        $refundId = trim($refundId);
        $vendorOrderId = trim($vendorOrderId);
        $currency = strtoupper(trim($currency));

        if (
            $storeId === ''
            || $refundId === ''
            || $vendorOrderId === ''
        ) {
            throw new InvalidArgumentException(
                'Store, refund and vendor order are required.'
            );
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(
                'A valid three-letter currency is required.'
            );
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException(
                'Refund debt amount must be greater than zero.'
            );
        }

        $idempotencyKey = 'refund-debt:' . $refundId;

        try {
            $metadata = json_encode(
                [
                    'vendor_order_id' => $vendorOrderId,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode refund debt metadata.',
                previous: $exception,
            );
        }

        /*
         * Insert the debt entry and update the vendor balance atomically.
         *
         * If the idempotency key already exists, the INSERT returns no rows,
         * so the balance is not incremented.
         */
        $result = $this->db->query(
            <<<'SQL'
                WITH inserted AS (
                    INSERT INTO public.vendor_debt_entries (
                        store_id,
                        currency,
                        entry_type,
                        amount_minor,
                        refund_id,
                        payout_id,
                        idempotency_key,
                        metadata
                    )
                    VALUES (
                        :store_id,
                        :currency,
                        'refund_debt_created',
                        :amount_minor,
                        :refund_id,
                        NULL,
                        :idempotency_key,
                        CAST(:metadata AS JSONB)
                    )
                    ON CONFLICT (idempotency_key) DO NOTHING
                    RETURNING
                        store_id,
                        currency,
                        amount_minor
                ),
                balance AS (
                    INSERT INTO public.vendor_debt_balances (
                        store_id,
                        currency,
                        outstanding_minor
                    )
                    SELECT
                        store_id,
                        currency,
                        amount_minor
                    FROM inserted
                    ON CONFLICT (store_id, currency)
                    DO UPDATE SET
                        outstanding_minor =
                            public.vendor_debt_balances.outstanding_minor
                            + EXCLUDED.outstanding_minor,
                        updated_at = NOW()
                    RETURNING outstanding_minor
                )
                SELECT inserted.amount_minor
                FROM inserted
                CROSS JOIN balance
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'refund_id' => $refundId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ],
        );

        $inserted = $result->fetch();

        if ($inserted !== false) {
            return (int) $inserted['amount_minor'];
        }

        /*
         * The idempotency key already exists.
         *
         * Verify that it represents the exact same refund debt operation.
         */
        $existing = $this->db->query(
            <<<'SQL'
                SELECT
                    store_id,
                    currency,
                    entry_type,
                    amount_minor,
                    refund_id,
                    payout_id,
                    metadata->>'vendor_order_id' AS vendor_order_id
                FROM public.vendor_debt_entries
                WHERE idempotency_key = :idempotency_key
                LIMIT 1
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
            ],
        )->fetch();

        if (
            $existing === false
            || (string) $existing['store_id'] !== $storeId
            || strtoupper(trim((string) $existing['currency'])) !== $currency
            || (string) $existing['entry_type'] !== 'refund_debt_created'
            || (int) $existing['amount_minor'] !== $amountMinor
            || (string) $existing['refund_id'] !== $refundId
            || $existing['payout_id'] !== null
            || (string) $existing['vendor_order_id'] !== $vendorOrderId
        ) {
            throw new DomainException(
                'Refund debt idempotency conflict.'
            );
        }

        /*
         * This refund debt was already recorded.
         * Do not increment the vendor balance again.
         */
        return 0;
    }
}
