<?php

declare(strict_types=1);

namespace App\Modules\Payout\Application\Services;

use App\Modules\Payout\Domain\Contracts\PayoutLedgerSettlementInterface;
use App\Modules\Payout\Domain\Repositories\VendorDebtRepositoryInterface;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class PayoutSettlementService
{
    public function __construct(
        private AdapterInterface $db,
        private VendorDebtRepositoryInterface $vendorDebtRepository,
        private PayoutLedgerSettlementInterface $ledgerSettlement,
    ) {
    }

    /**
     * Settle a successful Paystack transfer.
     *
     * All financial updates are committed atomically.
     */
    public function handleSuccessfulTransfer(
        string $payoutId,
        string $providerTransferId,
        string $providerReference,
        int $amountMinor,
        string $currency,
        array $providerResponse
    ): array {
        $payoutId = trim($payoutId);
        $providerTransferId = trim($providerTransferId);
        $providerReference = trim($providerReference);
        $currency = strtoupper(trim($currency));

        if (
            $payoutId === ''
            || $providerTransferId === ''
            || $providerReference === ''
            || $amountMinor <= 0
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid successful payout settlement data.'
            );
        }

        $this->db->begin();

        try {
            $payout = $this->lockPayout($payoutId);

            if ($payout === null) {
                throw new RuntimeException(
                    'Payout was not found.'
                );
            }

            if ((string) $payout['provider'] !== 'paystack') {
                throw new RuntimeException(
                    'Only Paystack payouts can be settled by this handler.'
                );
            }

            if ((string) $payout['status'] === 'success') {
                $this->assertSuccessfulPayoutMatches(
                    $payout,
                    $providerTransferId,
                    $providerReference,
                    $amountMinor,
                    $currency
                );

                $this->db->commit();

                return [
                    'payout_id' => $payoutId,
                    'status' => 'success',
                    'already_settled' => true,
                ];
            }

            if ((string) $payout['status'] !== 'processing') {
                throw new RuntimeException(
                    'Only processing payouts can be confirmed successful.'
                );
            }

            if (
                (int) $payout['amount_minor'] !== $amountMinor
                || strtoupper((string) $payout['currency']) !== $currency
            ) {
                throw new RuntimeException(
                    'Paystack transfer amount or currency does not match the reserved payout.'
                );
            }

            $storedReference = trim(
                (string) ($payout['provider_reference'] ?? '')
            );

            if (
                $storedReference !== ''
                && !hash_equals($storedReference, $providerReference)
            ) {
                throw new RuntimeException(
                    'Paystack transfer reference does not match the reserved payout.'
                );
            }

            $vendorPayable = $this->lockVendorPayable(
                (string) $payout['vendor_payable_id'],
                (string) $payout['store_id']
            );

            if ($vendorPayable === null) {
                throw new RuntimeException(
                    'Vendor payable associated with this payout was not found.'
                );
            }

            $ledgerResult = $this->ledgerSettlement->postPayout(
                [
                    'payout_id' => $payoutId,
                    'vendor_payable_id' => (string) $payout['vendor_payable_id'],
                    'vendor_order_id' => (string) $vendorPayable['vendor_order_id'],
                    'store_id' => (string) $payout['store_id'],
                    'amount_minor' => (int) $payout['amount_minor'],
                    'gross_amount_minor' => (int) $payout['gross_amount_minor'],
                    'debt_recovered_minor' => (int) $payout['debt_recovered_minor'],
                    'currency' => $currency,
                    'provider' => 'paystack',
                ],
                $vendorPayable
            );

            $this->vendorDebtRepository->confirmPayoutRecovery(
                (string) $payout['store_id'],
                $payoutId,
                $currency
            );

            $updateResult = $this->db->query(
                <<<'SQL'
                UPDATE public.payouts
                SET
                    status = 'success',
                    provider_reference = :provider_reference,
                    provider_transfer_id = :provider_transfer_id,
                    provider_response = CAST(:provider_response AS jsonb),
                    processed_at = COALESCE(processed_at, NOW()),
                    confirmed_at = NOW(),
                    failure_reason = NULL,
                    updated_at = NOW()
                WHERE id = :payout_id
                  AND status = 'processing'
                RETURNING id
                SQL,
                [
                    'provider_reference' => $providerReference,
                    'provider_transfer_id' => $providerTransferId,
                    'provider_response' => json_encode(
                        [
                            'webhook' => $providerResponse,
                            'ledger' => $ledgerResult,
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                    'payout_id' => $payoutId,
                ]
            );

            if ($updateResult->fetch() === false) {
                throw new RuntimeException(
                    'Unable to confirm the successful payout.'
                );
            }

            $this->refreshVendorPayableStatus(
                (string) $payout['vendor_payable_id']
            );

            $this->db->commit();

            return [
                'payout_id' => $payoutId,
                'status' => 'success',
                'already_settled' => false,
                'ledger' => $ledgerResult,
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    /**
     * Settle a failed Paystack transfer.
     *
     * The reserved vendor debt is released and the payable becomes
     * eligible for another payout request.
     */
    public function handleFailedTransfer(
        string $payoutId,
        string $failureReason,
        array $providerResponse
    ): array {
        $payoutId = trim($payoutId);
        $failureReason = trim($failureReason);

        if ($payoutId === '' || $failureReason === '') {
            throw new InvalidArgumentException(
                'Payout ID and failure reason are required.'
            );
        }

        $this->db->begin();

        try {
            $payout = $this->lockPayout($payoutId);

            if ($payout === null) {
                throw new RuntimeException(
                    'Payout was not found.'
                );
            }

            if ((string) $payout['provider'] !== 'paystack') {
                throw new RuntimeException(
                    'Only Paystack payouts can be failed by this handler.'
                );
            }

            $currentStatus = (string) $payout['status'];

            if ($currentStatus === 'failed') {
                $this->db->commit();

                return [
                    'payout_id' => $payoutId,
                    'status' => 'failed',
                    'already_settled' => true,
                ];
            }

            if ($currentStatus === 'success') {
                throw new RuntimeException(
                    'A successful payout cannot be marked as failed.'
                );
            }

            if ($currentStatus !== 'processing') {
                throw new RuntimeException(
                    'Only processing payouts can be marked as failed.'
                );
            }

            $vendorPayable = $this->lockVendorPayable(
                (string) $payout['vendor_payable_id'],
                (string) $payout['store_id']
            );

            if ($vendorPayable === null) {
                throw new RuntimeException(
                    'Vendor payable associated with this payout was not found.'
                );
            }

            $this->vendorDebtRepository->releasePayoutReservation(
                (string) $payout['store_id'],
                $payoutId,
                (string) $payout['currency']
            );

            $updateResult = $this->db->query(
                <<<'SQL'
                UPDATE public.payouts
                SET
                    status = 'failed',
                    failure_reason = :failure_reason,
                    provider_response = CAST(:provider_response AS jsonb),
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :payout_id
                  AND status = 'processing'
                RETURNING id
                SQL,
                [
                    'failure_reason' => $failureReason,
                    'provider_response' => json_encode(
                        $providerResponse,
                        JSON_THROW_ON_ERROR
                    ),
                    'payout_id' => $payoutId,
                ]
            );

            if ($updateResult->fetch() === false) {
                throw new RuntimeException(
                    'Unable to mark the payout as failed.'
                );
            }

            $this->refreshVendorPayableStatus(
                (string) $payout['vendor_payable_id']
            );

            $this->db->commit();

            return [
                'payout_id' => $payoutId,
                'status' => 'failed',
                'already_settled' => false,
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    private function lockPayout(string $payoutId): ?array
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                id,
                vendor_payable_id,
                store_id,
                provider,
                provider_reference,
                provider_transfer_id,
                amount_minor,
                gross_amount_minor,
                debt_recovered_minor,
                currency,
                status
            FROM public.payouts
            WHERE id = :payout_id
            FOR UPDATE
            SQL,
            ['payout_id' => $payoutId]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
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
                vp.gross_minor,
                vp.commission_minor,
                vp.adjustment_minor,
                vp.refund_minor,
                vp.payable_minor,
                vp.status,
                vo.order_id
            FROM public.vendor_payables vp
            INNER JOIN public.vendor_orders vo
                ON vo.id = vp.vendor_order_id
            WHERE vp.id = :vendor_payable_id
              AND vp.store_id = :store_id
            FOR UPDATE OF vp
            SQL,
            [
                'vendor_payable_id' => $vendorPayableId,
                'store_id' => $storeId,
            ]
        );

        $row = $result->fetch();

        return $row === false ? null : $row;
    }

    private function assertSuccessfulPayoutMatches(
        array $payout,
        string $providerTransferId,
        string $providerReference,
        int $amountMinor,
        string $currency
    ): void {
        if (
            (int) $payout['amount_minor'] !== $amountMinor
            || strtoupper((string) $payout['currency']) !== $currency
            || (string) ($payout['provider_transfer_id'] ?? '') !== $providerTransferId
            || (string) ($payout['provider_reference'] ?? '') !== $providerReference
        ) {
            throw new RuntimeException(
                'Duplicate payout notification does not match the previously settled transfer.'
            );
        }
    }

    private function refreshVendorPayableStatus(
        string $vendorPayableId
    ): void {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vp.payable_minor,
                COALESCE(
                    SUM(
                        CASE
                            WHEN p.status = 'success'
                            THEN COALESCE(
                                p.gross_amount_minor,
                                p.amount_minor
                            )
                            ELSE 0
                        END
                    ),
                    0
                ) AS settled_minor
            FROM public.vendor_payables vp
            LEFT JOIN public.payouts p
                ON p.vendor_payable_id = vp.id
            WHERE vp.id = :vendor_payable_id
            GROUP BY vp.id, vp.payable_minor
            SQL,
            ['vendor_payable_id' => $vendorPayableId]
        );

        $row = $result->fetch();

        if ($row === false) {
            throw new RuntimeException(
                'Unable to calculate vendor payable settlement status.'
            );
        }

        $payableMinor = (int) $row['payable_minor'];
        $settledMinor = (int) $row['settled_minor'];

        $status = $settledMinor >= $payableMinor
            ? 'paid'
            : ($settledMinor > 0 ? 'partially_paid' : 'eligible');

        $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_payables
            SET
                status = :status,
                paid_at = CASE
                    WHEN :is_paid = TRUE THEN COALESCE(paid_at, NOW())
                    ELSE NULL
                END,
                updated_at = NOW()
            WHERE id = :vendor_payable_id
            SQL,
            [
                'status' => $status,
                'is_paid' => $status === 'paid',
                'vendor_payable_id' => $vendorPayableId,
            ]
        );
    }
}