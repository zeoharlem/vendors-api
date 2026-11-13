<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Persistence;

use App\Modules\Payout\Domain\Repositories\VendorDebtRepositoryInterface;
use InvalidArgumentException;
use RuntimeException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;

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
        $currency = $this->normalizeCurrency($currency);

        $idempotencyKey = 'refund.debt.created:' . $refundId;

        $this->db->query(
            <<<'SQL'
            INSERT INTO public.vendor_debt_balances (
                store_id,
                currency,
                outstanding_minor,
                reserved_minor
            )
            VALUES (
                :store_id,
                :currency,
                0,
                0
            )
            ON CONFLICT (store_id, currency) DO NOTHING
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );

        $existing = $this->findEntryByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            $this->assertEntryMatches(
                $existing,
                $storeId,
                $currency,
                $amountMinor,
                'refund_debt_created',
                $refundId,
                null
            );

            return 0;
        }

        $insertResult = $this->db->query(
            <<<'SQL'
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
                CAST(:metadata AS jsonb)
            )
            ON CONFLICT (idempotency_key) DO NOTHING
            RETURNING id
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'refund_id' => $refundId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => json_encode(
                    ['vendor_order_id' => $vendorOrderId],
                    JSON_THROW_ON_ERROR
                ),
            ]
        );

        $inserted = $insertResult->fetch();

        if ($inserted === false) {
            $existing = $this->findEntryByIdempotencyKey($idempotencyKey);

            if ($existing === null) {
                throw new RuntimeException(
                    'Unable to verify the existing vendor debt entry.'
                );
            }

            $this->assertEntryMatches(
                $existing,
                $storeId,
                $currency,
                $amountMinor,
                'refund_debt_created',
                $refundId,
                null
            );

            return 0;
        }

        $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_debt_balances
            SET
                outstanding_minor = outstanding_minor + :amount_minor,
                updated_at = NOW()
            WHERE store_id = :store_id
              AND currency = :currency
            SQL,
            [
                'amount_minor' => $amountMinor,
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );

        return $amountMinor;
    }

    public function reserveAgainstPayout(
        string $storeId,
        string $payoutId,
        string $currency,
        int $grossPayoutMinor
    ): int {
        $this->validateAmount($grossPayoutMinor);
        $currency = $this->normalizeCurrency($currency);

        $idempotencyKey = 'payout.debt.reserved:' . $payoutId;

        $this->ensureBalanceExists($storeId, $currency);

        $existing = $this->findEntryByIdempotencyKey($idempotencyKey);

        if ($existing !== null) {
            $this->assertEntryMatches(
                $existing,
                $storeId,
                $currency,
                (int) $existing['amount_minor'],
                'payout_debt_reserved',
                null,
                $payoutId
            );

            return (int) $existing['amount_minor'];
        }

        $balance = $this->lockBalance($storeId, $currency);

        $outstanding = (int) $balance['outstanding_minor'];
        $reserved = (int) $balance['reserved_minor'];

        $availableDebt = max(0, $outstanding - $reserved);

        $recoveryAmount = min(
            $availableDebt,
            $grossPayoutMinor
        );

        if ($recoveryAmount === 0) {
            return 0;
        }

        $this->db->query(
            <<<'SQL'
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
                'payout_debt_reserved',
                :amount_minor,
                NULL,
                :payout_id,
                :idempotency_key,
                CAST(:metadata AS jsonb)
            )
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $recoveryAmount,
                'payout_id' => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => json_encode(
                    ['gross_payout_minor' => $grossPayoutMinor],
                    JSON_THROW_ON_ERROR
                ),
            ]
        );

        $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_debt_balances
            SET
                reserved_minor = reserved_minor + :amount_minor,
                updated_at = NOW()
            WHERE store_id = :store_id
              AND currency = :currency
              AND outstanding_minor - reserved_minor >= :amount_minor
            SQL,
            [
                'amount_minor' => $recoveryAmount,
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );

        return $recoveryAmount;
    }

    public function confirmPayoutRecovery(
        string $storeId,
        string $payoutId,
        string $currency
    ): int {
        $currency = $this->normalizeCurrency($currency);

        $reservation = $this->findEntryByIdempotencyKey(
            'payout.debt.reserved:' . $payoutId
        );

        if ($reservation === null) {
            return 0;
        }

        $this->assertEntryMatches(
            $reservation,
            $storeId,
            $currency,
            (int) $reservation['amount_minor'],
            'payout_debt_reserved',
            null,
            $payoutId
        );

        $amount = (int) $reservation['amount_minor'];

        $recoveredKey = 'payout.debt.recovered:' . $payoutId;
        $releasedKey = 'payout.debt.released:' . $payoutId;

        $recovered = $this->findEntryByIdempotencyKey($recoveredKey);

        if ($recovered !== null) {
            $this->assertEntryMatches(
                $recovered,
                $storeId,
                $currency,
                $amount,
                'payout_debt_recovered',
                null,
                $payoutId
            );

            return $amount;
        }

        if ($this->findEntryByIdempotencyKey($releasedKey) !== null) {
            throw new RuntimeException(
                'A released payout debt reservation cannot be recovered.'
            );
        }

        $this->lockBalance($storeId, $currency);

        $this->db->query(
            <<<'SQL'
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
                'payout_debt_recovered',
                :amount_minor,
                NULL,
                :payout_id,
                :idempotency_key,
                CAST(:metadata AS jsonb)
            )
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $amount,
                'payout_id' => $payoutId,
                'idempotency_key' => $recoveredKey,
                'metadata' => json_encode(
                    ['reservation_idempotency_key' => 'payout.debt.reserved:' . $payoutId],
                    JSON_THROW_ON_ERROR
                ),
            ]
        );

        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_debt_balances
            SET
                outstanding_minor = outstanding_minor - :amount_minor,
                reserved_minor = reserved_minor - :amount_minor,
                updated_at = NOW()
            WHERE store_id = :store_id
              AND currency = :currency
              AND outstanding_minor >= :amount_minor
              AND reserved_minor >= :amount_minor
            RETURNING outstanding_minor
            SQL,
            [
                'amount_minor' => $amount,
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );

        if ($result->fetch() === false) {
            throw new RuntimeException(
                'Vendor debt balance could not be updated for the confirmed payout.'
            );
        }

        return $amount;
    }

    public function releasePayoutReservation(
        string $storeId,
        string $payoutId,
        string $currency
    ): int {
        $currency = $this->normalizeCurrency($currency);

        $reservation = $this->findEntryByIdempotencyKey(
            'payout.debt.reserved:' . $payoutId
        );

        if ($reservation === null) {
            return 0;
        }

        $amount = (int) $reservation['amount_minor'];

        $this->assertEntryMatches(
            $reservation,
            $storeId,
            $currency,
            $amount,
            'payout_debt_reserved',
            null,
            $payoutId
        );

        $releasedKey = 'payout.debt.released:' . $payoutId;
        $recoveredKey = 'payout.debt.recovered:' . $payoutId;

        $released = $this->findEntryByIdempotencyKey($releasedKey);

        if ($released !== null) {
            $this->assertEntryMatches(
                $released,
                $storeId,
                $currency,
                $amount,
                'payout_debt_released',
                null,
                $payoutId
            );

            return $amount;
        }

        if ($this->findEntryByIdempotencyKey($recoveredKey) !== null) {
            throw new RuntimeException(
                'A recovered payout debt reservation cannot be released.'
            );
        }

        $this->lockBalance($storeId, $currency);

        $this->db->query(
            <<<'SQL'
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
                'payout_debt_released',
                :amount_minor,
                NULL,
                :payout_id,
                :idempotency_key,
                CAST(:metadata AS jsonb)
            )
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'amount_minor' => $amount,
                'payout_id' => $payoutId,
                'idempotency_key' => $releasedKey,
                'metadata' => json_encode(
                    ['reservation_idempotency_key' => 'payout.debt.reserved:' . $payoutId],
                    JSON_THROW_ON_ERROR
                ),
            ]
        );

        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_debt_balances
            SET
                reserved_minor = reserved_minor - :amount_minor,
                updated_at = NOW()
            WHERE store_id = :store_id
              AND currency = :currency
              AND reserved_minor >= :amount_minor
            RETURNING reserved_minor
            SQL,
            [
                'amount_minor' => $amount,
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );

        if ($result->fetch() === false) {
            throw new RuntimeException(
                'Vendor debt reservation could not be released.'
            );
        }

        return $amount;
    }

    public function outstandingBalance(
        string $storeId,
        string $currency
    ): int {
        $currency = $this->normalizeCurrency($currency);

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
                'currency' => $currency,
            ]
        );

        $row = $result->fetch();

        return $row === false
            ? 0
            : (int) $row['outstanding_minor'];
    }

    private function ensureBalanceExists(
        string $storeId,
        string $currency
    ): void {
        $this->db->query(
            <<<'SQL'
            INSERT INTO public.vendor_debt_balances (
                store_id,
                currency,
                outstanding_minor,
                reserved_minor
            )
            VALUES (
                :store_id,
                :currency,
                0,
                0
            )
            ON CONFLICT (store_id, currency) DO NOTHING
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );
    }

    private function lockBalance(
        string $storeId,
        string $currency
    ): array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                outstanding_minor,
                reserved_minor
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

        $row = $result->fetch();

        if ($row === false) {
            throw new RuntimeException(
                'Vendor debt balance was not found.'
            );
        }

        return $row;
    }

    private function findEntryByIdempotencyKey(
        string $idempotencyKey
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                id,
                store_id,
                currency,
                entry_type,
                amount_minor,
                refund_id,
                payout_id,
                idempotency_key
            FROM public.vendor_debt_entries
            WHERE idempotency_key = :idempotency_key
            LIMIT 1
            SQL,
            ['idempotency_key' => $idempotencyKey]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    private function assertEntryMatches(
        array $entry,
        string $storeId,
        string $currency,
        int $amountMinor,
        string $entryType,
        ?string $refundId,
        ?string $payoutId
    ): void {
        if (
            (string) $entry['store_id'] !== $storeId
            || strtoupper((string) $entry['currency']) !== $currency
            || (int) $entry['amount_minor'] !== $amountMinor
            || (string) $entry['entry_type'] !== $entryType
            || ($entry['refund_id'] !== null
                && (string) $entry['refund_id'] !== $refundId)
            || ($entry['payout_id'] !== null
                && (string) $entry['payout_id'] !== $payoutId)
            || ($refundId !== null && (string) $entry['refund_id'] !== $refundId)
            || ($payoutId !== null && (string) $entry['payout_id'] !== $payoutId)
        ) {
            throw new RuntimeException(
                'Vendor debt idempotency key was reused with different data.'
            );
        }
    }

    private function validateAmount(int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException(
                'Amount must be greater than zero.'
            );
        }
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(
                'A valid three-letter currency code is required.'
            );
        }

        return $currency;
    }
}