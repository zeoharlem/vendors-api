<?php

declare(strict_types=1);

namespace App\Modules\Payout\Application\Services;

use App\Modules\Payout\Domain\Contracts\PayoutLedgerSettlementInterface;
use App\Modules\Payout\Domain\Repositories\VendorDebtRepositoryInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class PaystackTransferWebhookService
{
    public function __construct(
        private AdapterInterface $db,
        private VendorDebtRepositoryInterface $vendorDebtRepository,
        private PayoutLedgerSettlementInterface $ledgerSettlement,
    ) {
    }

    /**
     * @param array{
     *     event?: mixed,
     *     data?: array<string, mixed>
     * } $event
     *
     * @return array<string, mixed>
     */
    public function handle(array $event): array
    {
        $eventType = trim((string) ($event['event'] ?? ''));
        $data = $event['data'] ?? null;

        if (!is_array($data)) {
            throw new RuntimeException('Invalid Paystack transfer payload.');
        }

        if (
            !in_array($eventType, [
                'transfer.success',
                'transfer.failed',
                'transfer.reversed',
            ], true)
        ) {
            throw new RuntimeException(
                sprintf('Unsupported Paystack transfer event: %s', $eventType)
            );
        }

        // if ($eventType === 'transfer.reversed') {
        //     throw new RuntimeException(
        //         'Paystack transfer reversal requires ledger reversal reconciliation.'
        //     );
        // }

        $reference = trim((string) ($data['reference'] ?? ''));
        $providerTransferId = isset($data['id'])
            ? trim((string) $data['id'])
            : '';

        $amountMinor = filter_var(
            $data['amount'] ?? null,
            FILTER_VALIDATE_INT
        );

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));

        if (
            $reference === ''
            || $amountMinor === false
            || $amountMinor <= 0
            || $currency === ''
        ) {
            throw new RuntimeException(
                'Paystack transfer event is missing required transfer details.'
            );
        }

        $this->db->begin();

        try {
            $result = $this->db->query(
                <<<'SQL'
                SELECT *
                FROM public.payouts
                WHERE provider = 'paystack'
                  AND provider_reference = :reference
                LIMIT 1
                FOR UPDATE
                SQL,
                [
                    'reference' => $reference,
                ]
            );

            $payout = $result->fetch();

            if (!is_array($payout)) {
                throw new RuntimeException(
                    'No payout matches the Paystack transfer reference.'
                );
            }

            $payoutId = (string) $payout['id'];
            $payoutStatus = (string) $payout['status'];

            $expectedAmount = (int) $payout['amount_minor'];
            $expectedCurrency = strtoupper(
                trim((string) $payout['currency'])
            );

            if ($amountMinor !== $expectedAmount) {
                throw new RuntimeException(
                    'Paystack transfer amount does not match the reserved payout.'
                );
            }

            if ($currency !== $expectedCurrency) {
                throw new RuntimeException(
                    'Paystack transfer currency does not match the reserved payout.'
                );
            }

            if (
                $providerTransferId !== ''
                && !empty($payout['provider_transfer_id'])
                && !hash_equals((string) $payout['provider_transfer_id'], $providerTransferId)
            ) {
                throw new RuntimeException(
                    'Paystack transfer ID does not match the reserved payout.'
                );
            }

            if ($payoutStatus === 'success') {
                if ($eventType === 'transfer.success') {
                    $this->db->commit();

                    return [
                        'processed' => true,
                        'duplicate' => true,
                        'payout_id' => $payoutId,
                        'status' => 'success',
                    ];
                }

                throw new RuntimeException(
                    'A finalized successful payout cannot be marked failed by a later webhook.'
                );
            }

            if ($payoutStatus === 'failed') {
                if ($eventType === 'transfer.failed') {
                    $this->db->commit();

                    return [
                        'processed' => true,
                        'duplicate' => true,
                        'payout_id' => $payoutId,
                        'status' => 'failed',
                    ];
                }

                throw new RuntimeException(
                    'A failed payout cannot be finalized as successful by this webhook.'
                );
            }

            // if (!in_array($payoutStatus, ['pending', 'processing'], true)) {
            //     throw new RuntimeException(
            //         sprintf('Payout cannot be finalized from status "%s".', $payoutStatus)
            //     );
            // }

            if ($eventType === 'transfer.reversed') {
                if (!in_array($payoutStatus, ['success', 'reversed'], true)) {
                    throw new RuntimeException(
                        'A reversed transfer was received for a payout that is not successful. Reconciliation is required.'
                    );
                }
            } elseif (!in_array($payoutStatus, ['pending', 'processing'], true)) {
                throw new RuntimeException(
                    sprintf('Payout cannot be finalized from status "%s".', $payoutStatus)
                );
            }

            $payableResult = $this->db->query(
                <<<'SQL'
                    SELECT
                        vp.*,
                        vo.id AS vendor_order_id,
                        vo.order_id
                    FROM public.vendor_payables vp
                    INNER JOIN public.vendor_orders vo
                        ON vo.id = vp.vendor_order_id
                    WHERE vp.id = :payable_id
                    AND vp.store_id = :store_id
                    LIMIT 1
                    FOR UPDATE OF vp
                SQL,
                [
                    'payable_id' => $payout['vendor_payable_id'],
                    'store_id' => $payout['store_id'],
                ]
            );

            $vendorPayable = $payableResult->fetch();

            if (!is_array($vendorPayable)) {
                throw new RuntimeException(
                    'Vendor payable associated with the payout was not found.'
                );
            }

            $providerResponse = json_encode(
                $event,
                JSON_THROW_ON_ERROR
            );

            if ($eventType === 'transfer.reversed') {
                if ($payoutStatus === 'reversed') {
                    $this->db->commit();

                    return [
                        'processed' => true,
                        'duplicate' => true,
                        'payout_id' => $payoutId,
                        'status' => 'reversed',
                    ];
                }

                $ledgerPayout = [
                    'id' => $payoutId,
                    'payout_id' => $payoutId,
                    'vendor_payable_id' => (string) $payout['vendor_payable_id'],
                    'store_id' => (string) $payout['store_id'],
                    'vendor_order_id' => (string) $vendorPayable['vendor_order_id'],
                    'amount_minor' => (int) $payout['amount_minor'],
                    'gross_amount_minor' => (int) $payout['gross_amount_minor'],
                    'debt_recovered_minor' => (int) $payout['debt_recovered_minor'],
                    'provider' => (string) $payout['provider'],
                    'currency' => $currency
                ];

                $reversal = $this->ledgerSettlement->reversePayout(
                    $ledgerPayout,
                    $vendorPayable
                );

                $updateResult = $this->db->query(
                    <<<'SQL'
        UPDATE public.payouts
        SET
            status = 'reversed',
            provider_transfer_id = COALESCE(
                provider_transfer_id,
                :provider_transfer_id
            ),
            provider_response = CAST(:provider_response AS JSONB),
            processed_at = COALESCE(processed_at, NOW()),
            updated_at = NOW()
        WHERE id = :id
          AND status = 'success'
        RETURNING id
        SQL,
                    [
                        'provider_transfer_id' => $providerTransferId !== ''
                            ? $providerTransferId
                            : null,
                        'provider_response' => $providerResponse,
                        'id' => $payoutId,
                    ]
                );

                if (!$updateResult->fetch()) {
                    throw new RuntimeException(
                        'Unable to finalize the reversed payout.'
                    );
                }

                $this->refreshVendorPayableStatus(
                    (string) $payout['vendor_payable_id']
                );

                $this->db->commit();

                return [
                    'processed' => true,
                    'duplicate' => false,
                    'payout_id' => $payoutId,
                    'status' => 'reversed',
                    'ledger_transaction_id' => $reversal['ledger_transaction_id'],
                ];
            }

            if ($eventType === 'transfer.success') {
                $ledgerPayout = [
                    'id' => $payoutId,
                    'payout_id' => $payoutId,
                    'vendor_payable_id' => (string) $payout['vendor_payable_id'],
                    'store_id' => (string) $payout['store_id'],
                    'vendor_order_id' => (string) $vendorPayable['vendor_order_id'],
                    'amount_minor' => (int) $payout['amount_minor'],
                    'gross_amount_minor' => (int) $payout['gross_amount_minor'],
                    'debt_recovered_minor' => (int) $payout['debt_recovered_minor'],
                    'provider' => (string) $payout['provider'],
                    'currency' => $currency,
                ];

                $this->ledgerSettlement->postPayout(
                    $ledgerPayout,
                    $vendorPayable
                );

                if ((int) $payout['debt_recovered_minor'] > 0) {
                    $this->vendorDebtRepository->confirmPayoutRecovery(
                        storeId: (string) $payout['store_id'],
                        payoutId: $payoutId,
                        currency: $currency,
                    );
                }

                $updateResult = $this->db->query(
                    <<<'SQL'
                    UPDATE public.payouts
                    SET
                        status = 'success',
                        provider_transfer_id = COALESCE(
                            provider_transfer_id,
                            :provider_transfer_id
                        ),
                        provider_response = CAST(:provider_response AS jsonb),
                        processed_at = COALESCE(processed_at, NOW()),
                        confirmed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                      AND status IN ('pending', 'processing')
                    RETURNING id
                    SQL,
                    [
                        'provider_transfer_id' => $providerTransferId !== ''
                            ? $providerTransferId
                            : null,
                        'provider_response' => $providerResponse,
                        'id' => $payoutId,
                    ]
                );

                if (!$updateResult->fetch()) {
                    throw new RuntimeException(
                        'Unable to finalize the successful payout.'
                    );
                }

                $this->refreshVendorPayableStatus(
                    (string) $payout['vendor_payable_id']
                );

                $this->db->commit();

                return [
                    'processed' => true,
                    'duplicate' => false,
                    'payout_id' => $payoutId,
                    'status' => 'success',
                ];
            }

            // transfer.failed
            if ((int) $payout['debt_recovered_minor'] > 0) {
                $this->vendorDebtRepository->releasePayoutReservation(
                    storeId: (string) $payout['store_id'],
                    payoutId: $payoutId,
                    currency: $currency,
                );
            }

            $updateResult = $this->db->query(
                <<<'SQL'
                UPDATE public.payouts
                SET
                    status = 'failed',
                    failure_reason = :failure_reason,
                    provider_transfer_id = COALESCE(
                        provider_transfer_id,
                        :provider_transfer_id
                    ),
                    provider_response = CAST(:provider_response AS jsonb),
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                  AND status IN ('pending', 'processing')
                RETURNING id
                SQL,
                [
                    'failure_reason' => trim(
                        (string) ($data['reason'] ?? $data['message'] ?? 'Paystack transfer failed.')
                    ),
                    'provider_transfer_id' => $providerTransferId !== ''
                        ? $providerTransferId
                        : null,
                    'provider_response' => $providerResponse,
                    'id' => $payoutId,
                ]
            );

            if (!$updateResult->fetch()) {
                throw new RuntimeException(
                    'Unable to finalize the failed payout.'
                );
            }

            $this->refreshVendorPayableStatus(
                (string) $payout['vendor_payable_id']
            );

            $this->db->commit();

            return [
                'processed' => true,
                'duplicate' => false,
                'payout_id' => $payoutId,
                'status' => 'failed',
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    private function refreshVendorPayableStatus(string $vendorPayableId): void
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT
                    vp.payable_minor,
                    COALESCE(
                    SUM(
                        CASE
                            WHEN p.status = 'success'
                                THEN COALESCE(p.gross_amount_minor, p.amount_minor)
                            WHEN p.status = 'reversed'
                                THEN p.debt_recovered_minor
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
            [
                'vendor_payable_id' => $vendorPayableId,
            ]
        );

        $balance = $result->fetch();

        if (!is_array($balance)) {
            throw new RuntimeException(
                'Unable to recalculate vendor payable after payout processing.'
            );
        }

        $payableMinor = (int) $balance['payable_minor'];
        $settledMinor = (int) $balance['settled_minor'];

        $newStatus = $settledMinor >= $payableMinor
            ? 'paid'
            : ($settledMinor > 0 ? 'partially_paid' : 'eligible');

        $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_payables
            SET
                status = CASE
                    WHEN status = 'on_hold' THEN 'on_hold'
                    ELSE :status
                END,
                paid_at = CASE
                    WHEN :is_paid = TRUE THEN COALESCE(paid_at, NOW())
                    ELSE NULL
                END,
                updated_at = NOW()
            WHERE id = :vendor_payable_id
            SQL,
            [
                'status' => $newStatus,
                'is_paid' => $newStatus === 'paid',
                'vendor_payable_id' => $vendorPayableId,
            ]
        );
    }
}
