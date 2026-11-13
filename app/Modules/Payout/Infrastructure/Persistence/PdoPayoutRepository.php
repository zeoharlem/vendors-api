<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Persistence;

use App\Modules\Payout\Domain\Entities\Payout;
use App\Modules\Payout\Domain\Enums\PayoutStatus;
use App\Modules\Payout\Domain\Repositories\PayoutRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class PdoPayoutRepository implements PayoutRepositoryInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function create(Payout $payout): Payout
    {
        $result = $this->db->query(
            <<<'SQL'
            INSERT INTO public.payouts (
                id,
                vendor_payable_id,
                store_id,
                payout_account_id,
                provider,
                idempotency_key,
                provider_reference,
                provider_transfer_id,
                amount_minor,
                currency,
                status,
                failure_reason,
                requested_at,
                processed_at,
                confirmed_at,
                provider_response,
                gross_amount_minor,
                debt_recovered_minor,
                created_at,
                updated_at
            )
            VALUES (
                :id,
                :vendor_payable_id,
                :store_id,
                :payout_account_id,
                :provider,
                :idempotency_key,
                :provider_reference,
                :provider_transfer_id,
                :amount_minor,
                :currency,
                :status,
                :failure_reason,
                :requested_at,
                :processed_at,
                :confirmed_at,
                CAST(:provider_response AS JSONB),
                :gross_amount_minor,
                :debt_recovered_minor,
                :created_at,
                :updated_at
            )
            RETURNING *
            SQL,
            [
                'id' => $payout->id(),
                'vendor_payable_id' => $payout->vendorPayableId(),
                'store_id' => $payout->storeId(),
                'payout_account_id' => $payout->payoutAccountId(),
                'provider' => $payout->provider(),
                'idempotency_key' => $payout->idempotencyKey(),
                'provider_reference' => $payout->providerReference(),
                'provider_transfer_id' => $payout->providerTransferId(),
                'amount_minor' => $payout->amountMinor(),
                'currency' => $payout->currency(),
                'status' => $payout->status()->value,
                'failure_reason' => $payout->failureReason(),
                'requested_at' => $payout->requestedAt()->format('Y-m-d H:i:sP'),
                'processed_at' => $payout->processedAt()?->format('Y-m-d H:i:sP'),
                'confirmed_at' => $payout->confirmedAt()?->format('Y-m-d H:i:sP'),
                'provider_response' => json_encode(
                    $payout->providerResponse(),
                    JSON_THROW_ON_ERROR
                ),
                'gross_amount_minor' => $payout->grossAmountMinor(),
                'debt_recovered_minor' => $payout->debtRecoveredMinor(),
                'created_at' => $payout->createdAt()->format('Y-m-d H:i:sP'),
                'updated_at' => $payout->updatedAt()->format('Y-m-d H:i:sP'),
            ]
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Unable to create payout.');
        }

        return $this->hydrate($row);
    }

    public function findById(string $id): ?Payout
    {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.payouts
            WHERE id = :id
            LIMIT 1
            SQL,
            ['id' => $id]
        );
    }

    public function findByIdForUpdate(string $id): ?Payout
    {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.payouts
            WHERE id = :id
            FOR UPDATE
            SQL,
            ['id' => $id]
        );
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?Payout
    {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.payouts
            WHERE idempotency_key = :idempotency_key
            LIMIT 1
            SQL,
            ['idempotency_key' => $idempotencyKey]
        );
    }

    public function findByProviderReference(
        string $provider,
        string $providerReference
    ): ?Payout {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.payouts
            WHERE provider = :provider
              AND provider_reference = :provider_reference
            LIMIT 1
            SQL,
            [
                'provider' => $provider,
                'provider_reference' => $providerReference,
            ]
        );
    }

    public function findByProviderTransferId(
        string $provider,
        string $providerTransferId
    ): ?Payout {
        return $this->findOne(
            <<<'SQL'
            SELECT *
            FROM public.payouts
            WHERE provider = :provider
              AND provider_transfer_id = :provider_transfer_id
            LIMIT 1
            SQL,
            [
                'provider' => $provider,
                'provider_transfer_id' => $providerTransferId,
            ]
        );
    }

    public function markProcessing(string $id): bool
    {
        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.payouts
            SET
                status = :processing,
                processed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
              AND status = :pending
              AND provider <> :internal
              AND amount_minor > 0
            RETURNING id
            SQL,
            [
                'id' => $id,
                'pending' => PayoutStatus::PENDING->value,
                'processing' => PayoutStatus::PROCESSING->value,
                'internal' => 'internal',
            ]
        );

        return is_array($result->fetch());
    }

    public function markSuccessful(
        string $id,
        ?string $providerTransferId,
        array $providerResponse
    ): bool {
        if ($providerTransferId === null || trim($providerTransferId) === '') {
            throw new InvalidArgumentException(
                'A provider transfer ID is required for a successful external payout.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.payouts
            SET
                status = :success,
                provider_transfer_id = :provider_transfer_id,
                provider_response = CAST(:provider_response AS JSONB),
                confirmed_at = COALESCE(confirmed_at, NOW()),
                updated_at = NOW()
            WHERE id = :id
              AND provider <> :internal
              AND amount_minor > 0
              AND status IN (:pending, :processing)
              AND (
                  provider_transfer_id IS NULL
                  OR provider_transfer_id = :provider_transfer_id
              )
            RETURNING id
            SQL,
            [
                'id' => $id,
                'success' => PayoutStatus::SUCCESS->value,
                'provider_transfer_id' => $providerTransferId,
                'provider_response' => json_encode(
                    $providerResponse,
                    JSON_THROW_ON_ERROR
                ),
                'internal' => 'internal',
                'pending' => PayoutStatus::PENDING->value,
                'processing' => PayoutStatus::PROCESSING->value,
            ]
        );

        return is_array($result->fetch());
    }

    public function markFailed(
        string $id,
        string $reason,
        array $providerResponse
    ): bool {
        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.payouts
            SET
                status = :failed,
                failure_reason = :failure_reason,
                provider_response = CAST(:provider_response AS JSONB),
                updated_at = NOW()
            WHERE id = :id
              AND provider <> :internal
              AND status IN (:pending, :processing)
            RETURNING id
            SQL,
            [
                'id' => $id,
                'failed' => PayoutStatus::FAILED->value,
                'failure_reason' => $reason,
                'provider_response' => json_encode(
                    $providerResponse,
                    JSON_THROW_ON_ERROR
                ),
                'internal' => 'internal',
                'pending' => PayoutStatus::PENDING->value,
                'processing' => PayoutStatus::PROCESSING->value,
            ]
        );

        return is_array($result->fetch());
    }

    private function findOne(string $sql, array $params): ?Payout
    {
        $result = $this->db->query($sql, $params);
        $row = $result->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): Payout
    {
        $providerResponse = $row['provider_response'] ?? null;

        if (is_string($providerResponse)) {
            $providerResponse = json_decode(
                $providerResponse,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        if (!is_array($providerResponse)) {
            $providerResponse = null;
        }

        return new Payout(
            id: (string) $row['id'],
            vendorPayableId: (string) $row['vendor_payable_id'],
            storeId: (string) $row['store_id'],
            payoutAccountId: isset($row['payout_account_id'])
            ? (string) $row['payout_account_id']
            : null,
            provider: (string) $row['provider'],
            idempotencyKey: (string) $row['idempotency_key'],
            providerReference: isset($row['provider_reference'])
            ? (string) $row['provider_reference']
            : null,
            providerTransferId: isset($row['provider_transfer_id'])
            ? (string) $row['provider_transfer_id']
            : null,
            amountMinor: (int) $row['amount_minor'],
            currency: trim((string) $row['currency']),
            status: PayoutStatus::from((string) $row['status']),
            failureReason: isset($row['failure_reason'])
            ? (string) $row['failure_reason']
            : null,
            requestedAt: new DateTimeImmutable((string) $row['requested_at']),
            processedAt: isset($row['processed_at'])
            ? new DateTimeImmutable((string) $row['processed_at'])
            : null,
            confirmedAt: isset($row['confirmed_at'])
            ? new DateTimeImmutable((string) $row['confirmed_at'])
            : null,
            providerResponse: $providerResponse,
            grossAmountMinor: isset($row['gross_amount_minor'])
            ? (int) $row['gross_amount_minor']
            : null,
            debtRecoveredMinor: (int) $row['debt_recovered_minor'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}