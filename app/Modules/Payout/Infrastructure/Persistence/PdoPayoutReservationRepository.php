<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Persistence;

use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class PdoPayoutReservationRepository
{
    public function __construct(
        private AdapterInterface $db,
        private PdoVendorDebtRepository $vendorDebtRepository,
    ) {
    }

    /**
     * Reserve the remaining vendor payable for payout.
     *
     * This method owns its database transaction.
     *
     * @return array{
     *     payout_id:string,
     *     vendor_payable_id:string,
     *     vendor_order_id:string,
     *     store_id:string,
     *     provider:string,
     *     recipient_code:?string,
     *     payout_account_id:?string,
     *     idempotency_key:string,
     *     gross_amount_minor:int,
     *     amount_minor:int,
     *     debt_recovered_minor:int,
     *     currency:string,
     *     status:string,
     *     is_internal_settlement:bool,
     *     already_reserved:bool
     * }
     */
    public function reserve(
        string $storeId,
        string $vendorPayableId,
        string $idempotencyKey,
        ?string $payoutAccountId = null
    ): array {
        $storeId = trim($storeId);
        $vendorPayableId = trim($vendorPayableId);
        $idempotencyKey = trim($idempotencyKey);

        if ($storeId === '' || $vendorPayableId === '') {
            throw new InvalidArgumentException(
                'Store ID and vendor payable ID are required.'
            );
        }

        if (
            $idempotencyKey === ''
            || strlen($idempotencyKey) > 128
        ) {
            throw new InvalidArgumentException(
                'A valid payout idempotency key is required.'
            );
        }

        $this->db->begin();

        try {
            $existing = $this->findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                $this->assertExistingPayoutMatches(
                    $existing,
                    $storeId,
                    $vendorPayableId
                );

                $result = $this->hydrateReservation(
                    $existing,
                    true
                );

                $this->db->commit();

                return $result;
            }

            $payable = $this->lockVendorPayable(
                $vendorPayableId,
                $storeId
            );

            if ($payable === null) {
                throw new RuntimeException(
                    'Vendor payable was not found for this store.'
                );
            }

            $status = (string) $payable['status'];

            if (!in_array($status, ['eligible', 'partially_paid'], true)) {
                throw new RuntimeException(
                    'This vendor payable is not eligible for payout.'
                );
            }

            $currency = strtoupper(trim((string) $payable['currency']));

            if ($currency !== 'NGN') {
                throw new RuntimeException(
                    'Only NGN vendor payouts are currently supported.'
                );
            }

            $this->assertNoActiveDispute(
                (string) $payable['vendor_order_id']
            );

            $this->assertNoActiveRefund(
                (string) $payable['vendor_order_id']
            );

            $this->assertNoActivePayout($vendorPayableId);

            $grossAmountMinor = $this->calculateRemainingPayable(
                $vendorPayableId,
                (int) $payable['payable_minor']
            );

            if ($grossAmountMinor <= 0) {
                throw new RuntimeException(
                    'There is no outstanding payable amount to withdraw.'
                );
            }

            /*
             * Insert the payout first because vendor_debt_entries.payout_id
             * references public.payouts.
             *
             * This initial amount is replaced after debt reservation.
             * The entire operation is inside one transaction.
             */
            $insertResult = $this->db->query(
                <<<'SQL'
                INSERT INTO public.payouts (
                    vendor_payable_id,
                    store_id,
                    provider,
                    idempotency_key,
                    amount_minor,
                    gross_amount_minor,
                    debt_recovered_minor,
                    currency,
                    status,
                    payout_account_id
                )
                VALUES (
                    :vendor_payable_id,
                    :store_id,
                    'paystack',
                    :idempotency_key,
                    :amount_minor,
                    :gross_amount_minor,
                    0,
                    :currency,
                    'pending',
                    NULL
                )
                RETURNING id
                SQL,
                [
                    'vendor_payable_id' => $vendorPayableId,
                    'store_id' => $storeId,
                    'idempotency_key' => $idempotencyKey,
                    'amount_minor' => $grossAmountMinor,
                    'gross_amount_minor' => $grossAmountMinor,
                    'currency' => $currency,
                ]
            );

            $inserted = $insertResult->fetch();

            if ($inserted === false) {
                throw new RuntimeException(
                    'Unable to create payout reservation.'
                );
            }

            $payoutId = (string) $inserted['id'];

            /*
             * Reserve outstanding vendor debt.
             * This does not reduce outstanding_minor.
             */
            $debtRecoveredMinor = $this->vendorDebtRepository
                ->reserveAgainstPayout(
                    $storeId,
                    $payoutId,
                    $currency,
                    $grossAmountMinor
                );

            $amountMinor = $grossAmountMinor - $debtRecoveredMinor;

            $isInternalSettlement = $amountMinor === 0;

            $provider = $isInternalSettlement
                ? 'internal'
                : 'paystack';

            $account = null;

            if (!$isInternalSettlement) {
                $account = $this->findActivePayoutAccount(
                    $storeId,
                    $currency,
                    $payoutAccountId
                );

                if ($account === null) {
                    throw new RuntimeException(
                        'No active payout account is available for this store.'
                    );
                }
            }

            $this->db->query(
                <<<'SQL'
                UPDATE public.payouts
                SET
                    provider = :provider,
                    amount_minor = :amount_minor,
                    gross_amount_minor = :gross_amount_minor,
                    debt_recovered_minor = :debt_recovered_minor,
                    payout_account_id = :payout_account_id,
                    updated_at = NOW()
                WHERE id = :payout_id
                SQL,
                [
                    'provider' => $provider,
                    'amount_minor' => $amountMinor,
                    'gross_amount_minor' => $grossAmountMinor,
                    'debt_recovered_minor' => $debtRecoveredMinor,
                    'payout_account_id' => $account['id'] ?? null,
                    'payout_id' => $payoutId,
                ]
            );

            /*
             * Block the payable from being reserved again while this payout
             * is pending or processing.
             */
            $this->db->query(
                <<<'SQL'
                UPDATE public.vendor_payables
                SET
                    status = 'payout_pending',
                    updated_at = NOW()
                WHERE id = :vendor_payable_id
                SQL,
                [
                    'vendor_payable_id' => $vendorPayableId,
                ]
            );

            $this->db->commit();

            return [
                'payout_id' => $payoutId,
                'vendor_payable_id' => $vendorPayableId,
                'vendor_order_id' => (string) $payable['vendor_order_id'],
                'store_id' => $storeId,
                'provider' => $provider,
                'recipient_code' => $account['recipient_code'] ?? null,
                'payout_account_id' => $account['id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'gross_amount_minor' => $grossAmountMinor,
                'amount_minor' => $amountMinor,
                'debt_recovered_minor' => $debtRecoveredMinor,
                'currency' => $currency,
                'status' => 'pending',
                'is_internal_settlement' => $isInternalSettlement,
                'already_reserved' => false,
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    private function lockVendorPayable(
        string $vendorPayableId,
        string $storeId
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vp.id,
                vp.vendor_order_id,
                vp.store_id,
                vp.currency,
                vp.payable_minor,
                vp.status
            FROM public.vendor_payables vp
            WHERE vp.id = :vendor_payable_id
              AND vp.store_id = :store_id
            FOR UPDATE
            SQL,
            [
                'vendor_payable_id' => $vendorPayableId,
                'store_id' => $storeId,
            ]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    private function assertNoActiveDispute(
        string $vendorOrderId
    ): void {
        $result = $this->db->query(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM public.disputes
                WHERE vendor_order_id = :vendor_order_id
                  AND status IN (
                      'open',
                      'under_review',
                      'awaiting_customer',
                      'awaiting_vendor'
                  )
            ) AS has_active_dispute
            SQL,
            ['vendor_order_id' => $vendorOrderId]
        );

        $row = $result->fetch();

        if ($row !== false && $this->toBoolean($row['has_active_dispute'])) {
            throw new RuntimeException(
                'Payout is blocked because this vendor order has an active dispute.'
            );
        }
    }

    private function assertNoActiveRefund(
        string $vendorOrderId
    ): void {
        $result = $this->db->query(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM public.refunds
                WHERE vendor_order_id = :vendor_order_id
                  AND status IN (
                      'pending',
                      'processing',
                      'needs_attention'
                  )
            ) AS has_active_refund
            SQL,
            ['vendor_order_id' => $vendorOrderId]
        );

        $row = $result->fetch();

        if ($row !== false && $this->toBoolean($row['has_active_refund'])) {
            throw new RuntimeException(
                'Payout is blocked because this vendor order has an unresolved refund.'
            );
        }
    }

    private function assertNoActivePayout(
        string $vendorPayableId
    ): void {
        $result = $this->db->query(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM public.payouts
                WHERE vendor_payable_id = :vendor_payable_id
                  AND status IN ('pending', 'processing')
            ) AS has_active_payout
            SQL,
            ['vendor_payable_id' => $vendorPayableId]
        );

        $row = $result->fetch();

        if ($row !== false && $this->toBoolean($row['has_active_payout'])) {
            throw new RuntimeException(
                'An active payout already exists for this vendor payable.'
            );
        }
    }

    private function calculateRemainingPayable(
        string $vendorPayableId,
        int $payableMinor
    ): int {
        $result = $this->db->query(
            <<<'SQL'
            SELECT COALESCE(
                SUM(
                    CASE
                        WHEN status = 'success'
                            THEN COALESCE(gross_amount_minor, amount_minor)
                        WHEN status = 'reversed'
                            THEN debt_recovered_minor
                        ELSE 0
                    END
                ),
                0
            ) AS settled_minor
            FROM public.payouts
            WHERE vendor_payable_id = :vendor_payable_id
              AND status IN ('success', 'reversed')
            SQL,
            ['vendor_payable_id' => $vendorPayableId]
        );

        $row = $result->fetch();

        $settledMinor = $row === false
            ? 0
            : (int) $row['settled_minor'];

        return max(0, $payableMinor - $settledMinor);
    }

    private function findActivePayoutAccount(
        string $storeId,
        string $currency,
        ?string $payoutAccountId
    ): ?array {
        if ($payoutAccountId !== null && trim($payoutAccountId) !== '') {
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        id,
                        recipient_code
                    FROM public.vendor_payout_accounts
                    WHERE id = :account_id
                    AND store_id = :store_id
                    AND currency = :currency
                    AND provider = 'paystack'
                    AND status = 'active'
                    LIMIT 1
                SQL,
                [
                    'account_id' => $payoutAccountId,
                    'store_id' => $storeId,
                    'currency' => $currency,
                ]
            );
        } else {
            $result = $this->db->query(
                <<<'SQL'
                    SELECT
                        id,
                        recipient_code
                    FROM public.vendor_payout_accounts
                    WHERE store_id = :store_id
                    AND currency = :currency
                    AND provider = 'paystack'
                    AND status = 'active'
                    AND is_default = TRUE
                    LIMIT 1
                SQL,
                [
                    'store_id' => $storeId,
                    'currency' => $currency,
                ]
            );
        }

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    private function findByIdempotencyKey(
        string $idempotencyKey
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                id,
                vendor_payable_id,
                store_id,
                provider,
                idempotency_key,
                amount_minor,
                gross_amount_minor,
                debt_recovered_minor,
                currency,
                status,
                payout_account_id
            FROM public.payouts
            WHERE idempotency_key = :idempotency_key
            LIMIT 1
            SQL,
            ['idempotency_key' => $idempotencyKey]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    private function assertExistingPayoutMatches(
        array $payout,
        string $storeId,
        string $vendorPayableId
    ): void {
        if (
            (string) $payout['store_id'] !== $storeId
            || (string) $payout['vendor_payable_id'] !== $vendorPayableId
        ) {
            throw new RuntimeException(
                'Payout idempotency key was already used for a different resource.'
            );
        }
    }

    private function hydrateReservation(
        array $payout,
        bool $alreadyReserved
    ): array {
        $account = null;

        if ($payout['payout_account_id'] !== null) {
            $result = $this->db->query(
                <<<'SQL'
                SELECT id, recipient_code
                FROM public.vendor_payout_accounts
                WHERE id = :account_id
                LIMIT 1
                SQL,
                ['account_id' => $payout['payout_account_id']]
            );

            $account = $result->fetch();
        }

        $vendorOrderResult = $this->db->query(
            <<<'SQL'
            SELECT vendor_order_id
            FROM public.vendor_payables
            WHERE id = :vendor_payable_id
            LIMIT 1
            SQL,
            ['vendor_payable_id' => $payout['vendor_payable_id']]
        );

        $payable = $vendorOrderResult->fetch();

        if ($payable === false) {
            throw new RuntimeException(
                'The vendor payable associated with this payout no longer exists.'
            );
        }

        $amountMinor = (int) $payout['amount_minor'];
        $debtRecoveredMinor = (int) $payout['debt_recovered_minor'];

        return [
            'payout_id' => (string) $payout['id'],
            'vendor_payable_id' => (string) $payout['vendor_payable_id'],
            'vendor_order_id' => (string) $payable['vendor_order_id'],
            'store_id' => (string) $payout['store_id'],
            'provider' => (string) $payout['provider'],
            'recipient_code' => $account === false || $account === null
                ? null
                : (string) $account['recipient_code'],
            'payout_account_id' => $payout['payout_account_id'] === null
                ? null
                : (string) $payout['payout_account_id'],
            'idempotency_key' => (string) $payout['idempotency_key'],
            'gross_amount_minor' => (int) $payout['gross_amount_minor'],
            'amount_minor' => $amountMinor,
            'debt_recovered_minor' => $debtRecoveredMinor,
            'currency' => strtoupper((string) $payout['currency']),
            'status' => (string) $payout['status'],
            'is_internal_settlement' => $amountMinor === 0
                && $debtRecoveredMinor > 0,
            'already_reserved' => $alreadyReserved,
        ];
    }

    private function toBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}