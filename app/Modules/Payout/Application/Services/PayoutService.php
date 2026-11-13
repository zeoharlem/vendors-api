<?php

declare(strict_types=1);

namespace App\Modules\Payout\Application\Services;

use App\Modules\Payout\Domain\Contracts\PayoutLedgerSettlementInterface;
use App\Modules\Payout\Domain\Repositories\VendorDebtRepositoryInterface;
use App\Modules\Payout\Domain\Contracts\PayoutProviderInterface;
use App\Modules\Payout\Domain\Exceptions\PayoutAccessDeniedException;
use App\Modules\Payout\Infrastructure\Persistence\PdoPayoutReservationRepository;
use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use DomainException;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class PayoutService
{
    public function __construct(
        private AdapterInterface $db,
        private PdoPayoutReservationRepository $reservationRepository,
        private VendorDebtRepositoryInterface $vendorDebtRepository,
        private PayoutProviderInterface $payoutProvider,
        private PayoutLedgerSettlementInterface $ledgerSettlement,
        private StoreAccessCheckerInterface $storeAccessChecker
    ) {
    }

    /**
     * Request a payout for a vendor payable.
     *
     * The Paystack transfer is initiated only after the reservation
     * transaction has committed.
     */
    public function requestPayout(
        string $userId,
        string $storeId,
        string $vendorPayableId,
        string $idempotencyKey,
        ?string $payoutAccountId = null
    ): array {
        if (!$this->storeAccessChecker->canManageStore($userId, $storeId)) {
            throw new PayoutAccessDeniedException(
                'You are not authorized to request payouts for this store.'
            );
        }

        $idempotencyKey = trim($idempotencyKey);

        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 128) {
            throw new DomainException(
                'The idempotency key must be between 8 and 128 characters.'
            );
        }

        $reservation = $this->reservationRepository->reserve(
            $storeId,
            $vendorPayableId,
            $idempotencyKey,
            $payoutAccountId
        );

        /*
         * Internal settlement is safe to retry because its ledger
         * transaction and debt recovery are idempotent.
         */
        if ($reservation['is_internal_settlement']) {
            return $this->settleInternalPayout($reservation);
        }

        /*
         * Never initiate a second provider transfer for an existing
         * reservation. The original transfer may already be in progress.
         */
        if ($reservation['already_reserved']) {
            return $reservation;
        }

        return $this->initiatePaystackTransfer($reservation);
    }

    private function initiatePaystackTransfer(array $reservation): array
    {
        $payoutId = (string) $reservation['payout_id'];

        $recipientCode = trim(
            (string) ($reservation['recipient_code'] ?? '')
        );

        if ($recipientCode === '') {
            throw new RuntimeException(
                'The payout recipient code is missing.'
            );
        }

        $reference = 'tr_' . substr(
            hash('sha256', (string) $reservation['idempotency_key']),
            0,
            40
        );

        /*
         * Claim the payout before making the external request.
         * A timeout must not cause another transfer to be initiated.
         */
        $claimResult = $this->db->query(
            <<<'SQL'
            UPDATE public.payouts
            SET
                status = 'processing',
                updated_at = NOW()
            WHERE id = :payout_id
              AND status = 'pending'
              AND provider = 'paystack'
            RETURNING id
            SQL,
            ['payout_id' => $payoutId]
        );

        if ($claimResult->fetch() === false) {
            throw new RuntimeException(
                'The payout could not be claimed for provider processing.'
            );
        }

        $referenceResult = $this->db->query(
            <<<'SQL'
                UPDATE public.payouts
                SET
                    provider_reference = :reference,
                    updated_at = NOW()
                WHERE id = :payout_id
                AND status = 'processing'
                AND provider = 'paystack'
                AND (
                    provider_reference IS NULL
                    OR provider_reference = :reference
                )
                RETURNING id
            SQL,
            [
                'reference' => $reference,
                'payout_id' => $payoutId,
            ]
        );

        if ($referenceResult->fetch() === false) {
            throw new RuntimeException(
                'The payout transfer reference could not be persisted. '
                . 'The payout remains processing and requires reconciliation.'
            );
        }

        try {
            $providerResponse = $this->payoutProvider->initiateTransfer(
                $recipientCode,
                (int) $reservation['amount_minor'],
                (string) $reservation['currency'],
                $reference,
                'Vendor payout ' . $payoutId
            );
        } catch (Throwable $exception) {
            /*
             * Do not mark failed or release the debt reservation here.
             * A network timeout does not prove that Paystack rejected
             * the transfer. Reconciliation must determine its outcome.
             */
            throw new RuntimeException(
                'The payout transfer outcome is uncertain. The payout remains '
                . 'processing and must be reconciled before retrying.',
                previous: $exception
            );
        }

        if (!is_array($providerResponse)) {
            throw new RuntimeException(
                'The payout provider returned an invalid response.'
            );
        }

        $data = isset($providerResponse['data'])
            && is_array($providerResponse['data'])
            ? $providerResponse['data']
            : $providerResponse;

        $providerReference = trim((string) ($data['reference'] ?? ''));

        if (
            $providerReference !== '' && !hash_equals($reference, $providerReference)
        ) {
            throw new RuntimeException(
                'Paystack returned a transfer reference that does not match '
                . 'the submitted reference. The payout remains processing '
                . 'for reconciliation.'
            );
        }

        $providerTransferId = trim(
            (string) (
                $data['provider_transfer_id']
                ?? $data['transfer_id']
                ?? $data['id']
                ?? ''
            )
        );

        /*
         * Save the provider response even when its response shape is
         * incomplete. The transfer has already been submitted, so do not
         * release the reservation or initiate another transfer.
         */
        $this->db->query(
            <<<'SQL'
            UPDATE public.payouts
            SET
                provider_reference = :provider_reference,
                provider_transfer_id = :provider_transfer_id,
                provider_response = CAST(:provider_response AS jsonb),
                updated_at = NOW()
            WHERE id = :payout_id
              AND status = 'processing'
            SQL,
            [
                'provider_reference' => $providerReference !== ''
                    ? $providerReference
                    : null,
                'provider_transfer_id' => $providerTransferId !== ''
                    ? $providerTransferId
                    : null,
                'provider_response' => json_encode(
                    $providerResponse,
                    JSON_THROW_ON_ERROR
                ),
                'payout_id' => $payoutId,
            ]
        );

        if (
            $providerTransferId === ''
            || $providerReference === ''
        ) {
            throw new RuntimeException(
                'Paystack accepted or may have accepted the transfer, '
                . 'but the response did not contain the expected transfer '
                . 'identifier. The payout remains processing for reconciliation.'
            );
        }

        return [
            ...$reservation,
            'status' => 'processing',
            'provider_reference' => $providerReference,
            'provider_transfer_id' => $providerTransferId,
            'provider_response' => $providerResponse,
        ];
    }

    private function settleInternalPayout(array $reservation): array
    {
        $payoutId = (string) $reservation['payout_id'];

        $this->db->begin();

        try {
            $payoutResult = $this->db->query(
                <<<'SQL'
                SELECT
                    id,
                    vendor_payable_id,
                    store_id,
                    provider,
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

            $payout = $payoutResult->fetch();

            if ($payout === false) {
                throw new RuntimeException(
                    'The internal payout record was not found.'
                );
            }

            if (
                (string) $payout['provider'] !== 'internal'
                || (int) $payout['amount_minor'] !== 0
                || (int) $payout['debt_recovered_minor'] <= 0
                || (int) $payout['gross_amount_minor']
                !== (int) $payout['debt_recovered_minor']
            ) {
                throw new RuntimeException(
                    'The payout does not qualify for internal debt settlement.'
                );
            }

            if ((string) $payout['status'] === 'success') {
                $this->db->commit();

                return [
                    ...$reservation,
                    'status' => 'success',
                    'already_settled' => true,
                ];
            }

            if ((string) $payout['status'] !== 'pending') {
                throw new RuntimeException(
                    'The internal payout is not in a settleable state.'
                );
            }

            $payableResult = $this->db->query(
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
                    vo.order_id
                FROM public.vendor_payables vp
                INNER JOIN public.vendor_orders vo
                    ON vo.id = vp.vendor_order_id
                WHERE vp.id = :vendor_payable_id
                  AND vp.store_id = :store_id
                FOR UPDATE OF vp
                SQL,
                [
                    'vendor_payable_id' => $payout['vendor_payable_id'],
                    'store_id' => $payout['store_id'],
                ]
            );

            $vendorPayable = $payableResult->fetch();

            if ($vendorPayable === false) {
                throw new RuntimeException(
                    'The vendor payable for this payout was not found.'
                );
            }

            /*
             * Post the vendor payable debit and vendor receivable credit.
             * The adapter calls LedgerPostingService::postVendorPayout().
             */
            $ledgerResult = $this->ledgerSettlement->postPayout(
                [
                    'payout_id' => (string) $payout['id'],
                    'vendor_payable_id' => (string) $payout['vendor_payable_id'],
                    'vendor_order_id' => (string) $vendorPayable['vendor_order_id'],
                    'store_id' => (string) $payout['store_id'],
                    'amount_minor' => (int) $payout['amount_minor'],
                    'gross_amount_minor' => (int) $payout['gross_amount_minor'],
                    'debt_recovered_minor' => (int) $payout['debt_recovered_minor'],
                    'currency' => strtoupper((string) $payout['currency']),
                    'provider' => 'internal',
                ],
                $vendorPayable
            );

            /*
             * Only now is the reserved debt actually recovered.
             */
            $this->vendorDebtRepository->confirmPayoutRecovery(
                (string) $payout['store_id'],
                $payoutId,
                (string) $payout['currency']
            );

            $updateResult = $this->db->query(
                <<<'SQL'
                UPDATE public.payouts
                SET
                    status = 'success',
                    processed_at = NOW(),
                    confirmed_at = NOW(),
                    provider_response = CAST(:provider_response AS jsonb),
                    updated_at = NOW()
                WHERE id = :payout_id
                  AND status = 'pending'
                RETURNING id
                SQL,
                [
                    'provider_response' => json_encode(
                        [
                            'settlement_type' => 'internal_debt_offset',
                            'ledger' => $ledgerResult,
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                    'payout_id' => $payoutId,
                ]
            );

            if ($updateResult->fetch() === false) {
                throw new RuntimeException(
                    'Unable to finalize the internal payout.'
                );
            }

            $this->refreshVendorPayableStatus(
                (string) $payout['vendor_payable_id']
            );

            $this->db->commit();

            return [
                ...$reservation,
                'status' => 'success',
                'already_settled' => false,
                'ledger' => $ledgerResult,
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
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
                                THEN COALESCE(p.gross_amount_minor, p.amount_minor)
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
                'Unable to calculate the vendor payable settlement status.'
            );
        }

        $payableMinor = (int) $row['payable_minor'];
        $settledMinor = (int) $row['settled_minor'];

        $status = $settledMinor >= $payableMinor
            ? 'paid'
            : 'partially_paid';

        $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_payables
            SET
                status = :status,
                paid_at = CASE
                    WHEN :is_paid = TRUE THEN NOW()
                    ELSE paid_at
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