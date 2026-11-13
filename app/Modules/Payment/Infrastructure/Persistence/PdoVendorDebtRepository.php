<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Repositories\VendorDebtRepositoryInterface;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoVendorDebtRepository implements VendorDebtRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db
    ) {
    }

    public function recordRefundDebt(
        string $storeId,
        string $refundId,
        string $vendorOrderId,
        string $currency,
        int $amountMinor
    ): int {
        $this->validateAmount($amountMinor);

        $currency = strtoupper(trim($currency));

        if ($currency === '') {
            throw new InvalidArgumentException('Currency is required.');
        }

        $result = $this->db->query(
            <<<'SQL'
            WITH inserted AS (
                INSERT INTO public.vendor_debt_entries (
                    store_id,
                    currency,
                    entry_type,
                    amount_minor,
                    refund_id,
                    idempotency_key,
                    metadata
                )
                VALUES (
                    :store_id,
                    :currency,
                    'refund_debt_created',
                    :amount_minor,
                    :refund_id,
                    :idempotency_key,
                    CAST(:metadata AS JSONB)
                )
                ON CONFLICT (idempotency_key) DO NOTHING
                RETURNING store_id, currency, amount_minor
            ),
            balance AS (
                INSERT INTO public.vendor_debt_balances (
                    store_id,
                    currency,
                    outstanding_minor
                )
                SELECT store_id, currency, amount_minor
                FROM inserted
                ON CONFLICT (store_id, currency)
                DO UPDATE SET
                    outstanding_minor =
                        public.vendor_debt_balances.outstanding_minor
                        + EXCLUDED.outstanding_minor,
                    updated_at = NOW()
                RETURNING outstanding_minor
            )
            SELECT outstanding_minor FROM balance
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'refund_id' => $refundId,
                'idempotency_key' => 'refund-debt:' . $refundId,
                'metadata' => json_encode(
                    ['vendor_order_id' => $vendorOrderId],
                    JSON_THROW_ON_ERROR
                ),
            ]
        );

        $row = $result->fetch();

        if ($row !== false) {
            return $amountMinor;
        }

        /*
         * The idempotency key already exists.
         * Do not increase the debt balance a second time.
         */
        $existing = $this->db->query(
            <<<'SQL'
            SELECT amount_minor
            FROM public.vendor_debt_entries
            WHERE idempotency_key = :idempotency_key
              AND store_id = :store_id
              AND refund_id = :refund_id
            LIMIT 1
            SQL,
            [
                'idempotency_key' => 'refund-debt:' . $refundId,
                'store_id' => $storeId,
                'refund_id' => $refundId,
            ]
        )->fetch();

        if (
            $existing === false
            || (int) $existing['amount_minor'] !== $amountMinor
        ) {
            throw new RuntimeException(
                'Refund debt idempotency conflict.'
            );
        }

        return 0;
    }

    public function recoverAgainstPayout(
        string $storeId,
        string $payoutId,
        string $currency,
        int $grossPayoutMinor
    ): int {
        $this->validateAmount($grossPayoutMinor);

        $currency = strtoupper(trim($currency));

        $result = $this->db->query(
            <<<'SQL'
            SELECT outstanding_minor
            FROM public.vendor_debt_balances
            WHERE store_id = :store_id
              AND currency = :currency
            FOR UPDATE
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );

        $balance = $result->fetch();

        if ($balance === false) {
            return 0;
        }

        $outstandingMinor = (int) $balance['outstanding_minor'];

        $recoveryMinor = min(
            $grossPayoutMinor,
            $outstandingMinor
        );

        if ($recoveryMinor <= 0) {
            return 0;
        }

        $idempotencyKey = 'payout-debt:' . $payoutId;

        $existing = $this->db->query(
            <<<'SQL'
            SELECT amount_minor
            FROM public.vendor_debt_entries
            WHERE idempotency_key = :idempotency_key
            LIMIT 1
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
            ]
        )->fetch();

        if ($existing !== false) {
            return (int) $existing['amount_minor'];
        }

        $updated = $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_debt_balances
            SET
                outstanding_minor = outstanding_minor - :amount_minor,
                updated_at = NOW()
            WHERE store_id = :store_id
              AND currency = :currency
              AND outstanding_minor >= :amount_minor
            RETURNING outstanding_minor
            SQL,
            [
                'amount_minor' => $recoveryMinor,
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        )->fetch();

        if ($updated === false) {
            throw new RuntimeException(
                'Vendor debt balance could not be reduced safely.'
            );
        }

        $this->db->query(
            <<<'SQL'
            INSERT INTO public.vendor_debt_entries (
                store_id,
                currency,
                entry_type,
                amount_minor,
                payout_id,
                idempotency_key,
                metadata
            )
            VALUES (
                :store_id,
                :currency,
                'payout_debt_recovered',
                :amount_minor,
                :payout_id,
                :idempotency_key,
                CAST(:metadata AS JSONB)
            )
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $recoveryMinor,
                'payout_id' => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => json_encode(
                    ['gross_payout_minor' => $grossPayoutMinor],
                    JSON_THROW_ON_ERROR
                ),
            ]
        );

        return $recoveryMinor;
    }

    public function outstandingBalance(
        string $storeId,
        string $currency
    ): int {
        $result = $this->db->query(
            <<<'SQL'
            SELECT outstanding_minor
            FROM public.vendor_debt_balances
            WHERE store_id = :store_id
              AND currency = :currency
            LIMIT 1
            SQL,
            [
                'store_id' => $storeId,
                'currency' => strtoupper(trim($currency)),
            ]
        );

        $row = $result->fetch();

        return $row === false
            ? 0
            : (int) $row['outstanding_minor'];
    }

    private function validateAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException(
                'The amount must be greater than zero.'
            );
        }
    }
}