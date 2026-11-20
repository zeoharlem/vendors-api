<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Persistence;

use App\Modules\Payout\Domain\Repositories\VendorDebtRepositoryInterface;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;

final readonly class PdoVendorDebtRepository implements VendorDebtRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    /**
     * Reserve available vendor debt against a payout.
     *
     * The amount reserved is the smaller of:
     * - the vendor's currently unreserved debt;
     * - the payout's gross amount.
     *
     * Must run inside the caller's transaction.
     */
    public function reserveAgainstPayout(
        string $storeId,
        string $payoutId,
        string $currency,
        int $grossPayoutMinor,
    ): int {
        $storeId = $this->normalizeId($storeId, 'Store ID');
        $payoutId = $this->normalizeId($payoutId, 'Payout ID');
        $currency = $this->normalizeCurrency($currency);
        $this->validateAmount($grossPayoutMinor, allowZero: false);

        $this->ensureBalanceExists($storeId, $currency);

        // Serialize all debt changes for this store and currency.
        $balance = $this->lockBalance($storeId, $currency);

        $reservationKey = "payout.debt.reserved:{$payoutId}";

        // Idempotent retry.
        $existing = $this->findEntryByIdempotencyKey($reservationKey);

        if ($existing !== null) {
            $this->assertEntryMatches(
                $existing,
                storeId: $storeId,
                payoutId: $payoutId,
                currency: $currency,
                entryType: 'payout_debt_reserved',
            );

            return (int) $existing['amount_minor'];
        }

        $outstanding = (int) $balance['outstanding_minor'];
        $reserved = (int) $balance['reserved_minor'];

        $availableDebt = max(0, $outstanding - $reserved);
        $recoveryAmount = min($availableDebt, $grossPayoutMinor);

        // Nothing to reserve. No lifecycle entry is necessary.
        if ($recoveryAmount === 0) {
            return 0;
        }

        $this->insertEntry(
            storeId: $storeId,
            currency: $currency,
            entryType: 'payout_debt_reserved',
            amountMinor: $recoveryAmount,
            payoutId: $payoutId,
            idempotencyKey: $reservationKey,
            metadata: [
                'gross_payout_minor' => $grossPayoutMinor,
                'reserved_amount_minor' => $recoveryAmount,
            ],
        );

        // RETURNING ensures that the reservation was actually applied.
        $updatedBalance = $this->queryOne(
            <<<'SQL'
                UPDATE public.vendor_debt_balances
                SET
                    reserved_minor = reserved_minor + :amount,
                    updated_at = NOW()
                WHERE store_id = :store_id
                  AND currency = :currency
                  AND outstanding_minor - reserved_minor >= :amount
                RETURNING reserved_minor
            SQL,
            [
                'amount' => $recoveryAmount,
                'store_id' => $storeId,
                'currency' => $currency,
            ],
        );

        if ($updatedBalance === null) {
            throw new DomainException(
                'Unable to reserve the vendor debt amount for this payout.'
            );
        }

        return $recoveryAmount;
    }

    /**
     * Confirm a previously reserved debt recovery after payout success.
     *
     * This decreases both outstanding debt and reserved debt.
     * Must run inside the caller's transaction.
     */
    public function confirmPayoutRecovery(
        string $storeId,
        string $payoutId,
        string $currency,
    ): int {
        $storeId = $this->normalizeId($storeId, 'Store ID');
        $payoutId = $this->normalizeId($payoutId, 'Payout ID');
        $currency = $this->normalizeCurrency($currency);

        $this->ensureBalanceExists($storeId, $currency);
        $this->lockBalance($storeId, $currency);

        $reservationKey = "payout.debt.reserved:{$payoutId}";
        $recoveredKey = "payout.debt.recovered:{$payoutId}";
        $releasedKey = "payout.debt.released:{$payoutId}";

        $reservation = $this->findEntryByIdempotencyKey($reservationKey);

        if ($reservation === null) {
            return 0;
        }

        $this->assertEntryMatches(
            $reservation,
            storeId: $storeId,
            payoutId: $payoutId,
            currency: $currency,
            entryType: 'payout_debt_reserved',
        );

        $amount = (int) $reservation['amount_minor'];

        // Recheck terminal entries only after acquiring the balance lock.
        $recovered = $this->findEntryByIdempotencyKey($recoveredKey);
        $released = $this->findEntryByIdempotencyKey($releasedKey);

        if ($recovered !== null) {
            $this->assertEntryMatches(
                $recovered,
                storeId: $storeId,
                payoutId: $payoutId,
                currency: $currency,
                entryType: 'payout_debt_recovered',
                expectedAmount: $amount,
            );

            return $amount;
        }

        if ($released !== null) {
            throw new DomainException(
                'This payout debt reservation has already been released.'
            );
        }

        $this->insertEntry(
            storeId: $storeId,
            currency: $currency,
            entryType: 'payout_debt_recovered',
            amountMinor: $amount,
            payoutId: $payoutId,
            idempotencyKey: $recoveredKey,
            metadata: [
                'reservation_id' => $reservation['id'],
                'reservation_idempotency_key' => $reservationKey,
            ],
        );

        $updatedBalance = $this->queryOne(
            <<<'SQL'
                UPDATE public.vendor_debt_balances
                SET
                    outstanding_minor = outstanding_minor - :amount,
                    reserved_minor = reserved_minor - :amount,
                    updated_at = NOW()
                WHERE store_id = :store_id
                  AND currency = :currency
                  AND outstanding_minor >= :amount
                  AND reserved_minor >= :amount
                RETURNING outstanding_minor, reserved_minor
            SQL,
            [
                'amount' => $amount,
                'store_id' => $storeId,
                'currency' => $currency,
            ],
        );

        if ($updatedBalance === null) {
            throw new DomainException(
                'Unable to confirm the vendor payout debt recovery.'
            );
        }

        return $amount;
    }

    /**
     * Release a reservation when a payout fails before recovery is confirmed.
     *
     * This decreases reserved debt only. Outstanding debt remains unchanged.
     * Must run inside the caller's transaction.
     */
    public function releasePayoutReservation(
        string $storeId,
        string $payoutId,
        string $currency,
    ): int {
        $storeId = $this->normalizeId($storeId, 'Store ID');
        $payoutId = $this->normalizeId($payoutId, 'Payout ID');
        $currency = $this->normalizeCurrency($currency);

        $this->ensureBalanceExists($storeId, $currency);
        $this->lockBalance($storeId, $currency);

        $reservationKey = "payout.debt.reserved:{$payoutId}";
        $recoveredKey = "payout.debt.recovered:{$payoutId}";
        $releasedKey = "payout.debt.released:{$payoutId}";

        $reservation = $this->findEntryByIdempotencyKey($reservationKey);

        if ($reservation === null) {
            return 0;
        }

        $this->assertEntryMatches(
            $reservation,
            storeId: $storeId,
            payoutId: $payoutId,
            currency: $currency,
            entryType: 'payout_debt_reserved',
        );

        $amount = (int) $reservation['amount_minor'];

        // Recheck terminal entries only after acquiring the balance lock.
        $released = $this->findEntryByIdempotencyKey($releasedKey);
        $recovered = $this->findEntryByIdempotencyKey($recoveredKey);

        if ($released !== null) {
            $this->assertEntryMatches(
                $released,
                storeId: $storeId,
                payoutId: $payoutId,
                currency: $currency,
                entryType: 'payout_debt_released',
                expectedAmount: $amount,
            );

            return $amount;
        }

        if ($recovered !== null) {
            throw new DomainException(
                'A confirmed payout debt recovery cannot be released.'
            );
        }

        $this->insertEntry(
            storeId: $storeId,
            currency: $currency,
            entryType: 'payout_debt_released',
            amountMinor: $amount,
            payoutId: $payoutId,
            idempotencyKey: $releasedKey,
            metadata: [
                'reservation_id' => $reservation['id'],
                'reservation_idempotency_key' => $reservationKey,
                'release_reason' => 'payout_failed',
            ],
        );

        $updatedBalance = $this->queryOne(
            <<<'SQL'
                UPDATE public.vendor_debt_balances
                SET
                    reserved_minor = reserved_minor - :amount,
                    updated_at = NOW()
                WHERE store_id = :store_id
                  AND currency = :currency
                  AND reserved_minor >= :amount
                RETURNING reserved_minor
            SQL,
            [
                'amount' => $amount,
                'store_id' => $storeId,
                'currency' => $currency,
            ],
        );

        if ($updatedBalance === null) {
            throw new DomainException(
                'Unable to release the vendor payout debt reservation.'
            );
        }

        return $amount;
    }

    /**
     * Return the vendor's current outstanding debt.
     *
     * Reserved debt is still part of outstanding debt until confirmed.
     */
    public function outstandingBalance(
        string $storeId,
        string $currency,
    ): int {
        $storeId = $this->normalizeId($storeId, 'Store ID');
        $currency = $this->normalizeCurrency($currency);

        $row = $this->queryOne(
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
            ],
        );

        return $row === null
            ? 0
            : (int) $row['outstanding_minor'];
    }

    /**
     * Restore debt previously recovered by a successful payout that was
     * subsequently reversed by the payout provider.
     *
     * This increases outstanding debt but does not change reserved debt.
     * Must run inside the caller's transaction.
     */
    public function restorePayoutRecovery(
        string $storeId,
        string $payoutId,
        string $currency,
    ): int {
        $storeId = $this->normalizeId($storeId, 'Store ID');
        $payoutId = $this->normalizeId($payoutId, 'Payout ID');
        $currency = $this->normalizeCurrency($currency);

        $this->ensureBalanceExists($storeId, $currency);
        $this->lockBalance($storeId, $currency);

        $recoveredKey = "payout.debt.recovered:{$payoutId}";
        $restoredKey = "payout.debt.recovery_restored:{$payoutId}";
        $releasedKey = "payout.debt.released:{$payoutId}";

        $recovered = $this->findEntryByIdempotencyKey($recoveredKey);

        if ($recovered === null) {
            return 0;
        }

        $this->assertEntryMatches(
            $recovered,
            storeId: $storeId,
            payoutId: $payoutId,
            currency: $currency,
            entryType: 'payout_debt_recovered',
        );

        $amount = (int) $recovered['amount_minor'];

        // A payout cannot be both released and recovered.
        $released = $this->findEntryByIdempotencyKey($releasedKey);

        if ($released !== null) {
            throw new DomainException(
                'A released payout reservation cannot be restored as a recovery.'
            );
        }

        // Idempotent reversal webhook/retry.
        $existingRestoration = $this->findEntryByIdempotencyKey($restoredKey);

        if ($existingRestoration !== null) {
            $this->assertEntryMatches(
                $existingRestoration,
                storeId: $storeId,
                payoutId: $payoutId,
                currency: $currency,
                entryType: 'payout_debt_recovery_restored',
                expectedAmount: $amount,
            );

            return $amount;
        }

        $this->insertEntry(
            storeId: $storeId,
            currency: $currency,
            entryType: 'payout_debt_recovery_restored',
            amountMinor: $amount,
            payoutId: $payoutId,
            idempotencyKey: $restoredKey,
            metadata: [
                'recovered_entry_id' => $recovered['id'],
                'recovered_idempotency_key' => $recoveredKey,
                'restoration_reason' => 'payout_reversed',
            ],
        );

        $updatedBalance = $this->queryOne(
            <<<'SQL'
                UPDATE public.vendor_debt_balances
                SET
                    outstanding_minor = outstanding_minor + :amount,
                    updated_at = NOW()
                WHERE store_id = :store_id
                  AND currency = :currency
                RETURNING outstanding_minor
            SQL,
            [
                'amount' => $amount,
                'store_id' => $storeId,
                'currency' => $currency,
            ],
        );

        if ($updatedBalance === null) {
            throw new DomainException(
                'Unable to restore the vendor payout debt recovery.'
            );
        }

        return $amount;
    }

    /**
     * Create the balance row if this is the store's first debt entry.
     */
    private function ensureBalanceExists(
        string $storeId,
        string $currency,
    ): void {
        $this->db->execute(
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
            ],
        );
    }

    /**
     * Lock the balance row to serialize all debt lifecycle operations
     * for a store and currency.
     *
     * @return array<string, mixed>
     */
    private function lockBalance(
        string $storeId,
        string $currency,
    ): array {
        $row = $this->queryOne(
            <<<'SQL'
                SELECT
                    id,
                    store_id,
                    currency,
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
            ],
        );

        if ($row === null) {
            throw new DomainException(
                'Vendor debt balance could not be found.'
            );
        }

        return $row;
    }

    /**
     * Insert an immutable vendor debt lifecycle entry.
     *
     * @param array<string, mixed> $metadata
     */
    private function insertEntry(
        string $storeId,
        string $currency,
        string $entryType,
        int $amountMinor,
        string $payoutId,
        string $idempotencyKey,
        array $metadata,
    ): void {
        $this->validateAmount($amountMinor, allowZero: false);

        $encodedMetadata = $this->encodeJson($metadata);

        $this->db->execute(
            <<<'SQL'
                INSERT INTO public.vendor_debt_entries (
                    store_id,
                    currency,
                    entry_type,
                    amount_minor,
                    payout_id,
                    refund_id,
                    idempotency_key,
                    metadata
                )
                VALUES (
                    :store_id,
                    :currency,
                    :entry_type,
                    :amount_minor,
                    :payout_id,
                    NULL,
                    :idempotency_key,
                    CAST(:metadata AS JSONB)
                )
                ON CONFLICT (idempotency_key) DO NOTHING
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
                'entry_type' => $entryType,
                'amount_minor' => $amountMinor,
                'payout_id' => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $encodedMetadata,
            ],
        );

        // A conflict can only be treated as success when the existing
        // entry is identical. The caller performs that verification.
    }

    /**
     * Find a debt entry using its globally unique idempotency key.
     *
     * @return array<string, mixed>|null
     */
    private function findEntryByIdempotencyKey(
        string $idempotencyKey,
    ): ?array {
        return $this->queryOne(
            <<<'SQL'
                SELECT
                    id,
                    store_id,
                    currency,
                    entry_type,
                    amount_minor,
                    refund_id,
                    payout_id,
                    idempotency_key,
                    metadata
                FROM public.vendor_debt_entries
                WHERE idempotency_key = :idempotency_key
                LIMIT 1
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    /**
     * Validate that an existing idempotency entry belongs to the
     * expected payout and represents the expected lifecycle operation.
     *
     * @param array<string, mixed> $entry
     */
    private function assertEntryMatches(
        array $entry,
        string $storeId,
        string $payoutId,
        string $currency,
        string $entryType,
        ?int $expectedAmount = null,
    ): void {
        $matches = (string) $entry['store_id'] === $storeId
            && (string) $entry['payout_id'] === $payoutId
            && strtoupper(trim((string) $entry['currency'])) === $currency
            && (string) $entry['entry_type'] === $entryType;

        if ($expectedAmount !== null) {
            $matches = $matches
                && (int) $entry['amount_minor'] === $expectedAmount;
        }

        if (!$matches) {
            throw new DomainException(
                'A vendor debt idempotency key already exists with conflicting data.'
            );
        }
    }

    /**
     * Execute a query expected to return at most one row.
     *
     * @param array<string, mixed> $bind
     * @return array<string, mixed>|null
     */
    private function queryOne(
        string $sql,
        array $bind = [],
    ): ?array {
        $result = $this->db->query($sql, $bind);
        $row = $result->fetch();

        return is_array($row) && $row !== [] ? $row : null;
    }

    private function normalizeId(
        string $id,
        string $label,
    ): string {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(
                "{$label} cannot be empty."
            );
        }

        return $id;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(
                'Currency must be a valid three-letter currency code.'
            );
        }

        return $currency;
    }

    private function validateAmount(
        int $amount,
        bool $allowZero,
    ): void {
        if ($amount < 0 || (!$allowZero && $amount === 0)) {
            throw new InvalidArgumentException(
                'Amount must be a positive integer.'
            );
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'Unable to encode vendor debt entry metadata.',
                previous: $exception,
            );
        }
    }
}
