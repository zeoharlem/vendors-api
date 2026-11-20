<?php

declare(strict_types=1);

namespace App\Modules\Payout\Application\Services;

use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Payout\Domain\Repositories\PayoutRepositoryInterface;
use App\Modules\Payout\Domain\Repositories\VendorPayoutRecoveryRepository;
use DomainException;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class VendorPayoutRecoveryService
{
    public function __construct(
        private AdapterInterface $db,
        private PayoutRepositoryInterface $payoutRepository,
        private VendorPayoutRecoveryRepository $recoveryRepository,
        private LedgerPostingService $ledgerPostingService,
    ) {
    }

    public function record(array $input, string $adminId): array
    {
        $payoutId = trim((string) ($input['payout_id'] ?? ''));
        $amount = filter_var(
            $input['amount_minor'] ?? null,
            FILTER_VALIDATE_INT
        );
        $returnMethod = trim((string) ($input['return_method'] ?? ''));
        $confirmationReference = trim(
            (string) ($input['confirmation_reference'] ?? '')
        );

        if (
            $payoutId === ''
            || $amount === false
            || $amount <= 0
            || $returnMethod === ''
            || $confirmationReference === ''
        ) {
            throw new InvalidArgumentException(
                'Payout, valid amount, return method and confirmation reference are required.'
            );
        }

        if (strlen($returnMethod) > 40) {
            throw new InvalidArgumentException(
                'Return method cannot exceed 40 characters.'
            );
        }

        $this->db->begin();

        try {
            // Serialize recovery reservations against the actual payout.
            $payout = $this->payoutRepository->findByIdForUpdate($payoutId);

            if ($payout === null) {
                throw new DomainException('Payout was not found.');
            }

            if ($payout->status()->value !== 'success') {
                throw new DomainException(
                    'Only successful payouts can have recoveries recorded.'
                );
            }

            $payable = $this->lockPayable($payout->vendorPayableId());

            if (
                (string) $payable['store_id'] !== $payout->storeId()
                || strtoupper(trim((string) $payable['currency']))
                !== strtoupper($payout->currency())
            ) {
                throw new DomainException(
                    'Payout and vendor payable details do not match.'
                );
            }

            $reserved = $this->recoveryRepository->sumReservedForPayout(
                $payoutId
            );

            if ($reserved + $amount > $payout->amountMinor()) {
                throw new DomainException(
                    'Recovery amount exceeds the remaining amount actually paid out.'
                );
            }

            $recovery = $this->recoveryRepository->createPending([
                'id' => $this->newUuid(),
                'payout_id' => $payoutId,
                'vendor_payable_id' => $payout->vendorPayableId(),
                'store_id' => $payout->storeId(),
                'amount_minor' => $amount,
                'currency' => strtoupper($payout->currency()),
                'return_method' => $returnMethod,
                'provider_reference' => $this->nullableString(
                    $input['provider_reference'] ?? null
                ),
                'confirmation_reference' => $confirmationReference,
                'notes' => $this->nullableString($input['notes'] ?? null),
                'recorded_by' => $adminId,
            ]);

            $this->db->commit();

            return $recovery;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function confirm(string $recoveryId, string $adminId): array
    {
        $this->db->begin();

        try {
            $recovery = $this->recoveryRepository->findByIdForUpdate(
                $recoveryId
            );

            if ($recovery === null) {
                throw new DomainException('Payout recovery was not found.');
            }

            if ($recovery['status'] !== 'pending') {
                throw new DomainException(
                    'Only pending payout recoveries can be confirmed.'
                );
            }

            // Keep the same lock order used when recording a recovery.
            $payout = $this->payoutRepository->findByIdForUpdate(
                (string) $recovery['payout_id']
            );

            if ($payout === null || $payout->status()->value !== 'success') {
                throw new DomainException(
                    'The associated payout is not successful.'
                );
            }

            $payable = $this->lockPayable(
                (string) $recovery['vendor_payable_id']
            );

            if (
                $payout->vendorPayableId() !== $recovery['vendor_payable_id']
                || $payout->storeId() !== $recovery['store_id']
                || strtoupper($payout->currency())
                !== strtoupper(trim((string) $recovery['currency']))
                || $payable['store_id'] !== $recovery['store_id']
            ) {
                throw new DomainException(
                    'Recovery, payout and vendor payable do not match.'
                );
            }

            $reserved = $this->recoveryRepository->sumReservedForPayout(
                (string) $recovery['payout_id'],
                $recoveryId
            );

            if (
                $reserved + (int) $recovery['amount_minor']
                > $payout->amountMinor()
            ) {
                throw new DomainException(
                    'Confirmed and pending recoveries exceed the actual payout amount.'
                );
            }

            // Ledger posting and confirmation are in the same DB transaction.
            $this->ledgerPostingService->postVendorPayoutRecovery(
                $recovery,
                [
                    'id' => $payout->id(),
                    'vendor_payable_id' => $payout->vendorPayableId(),
                    'store_id' => $payout->storeId(),
                    'currency' => $payout->currency(),
                    'amount_minor' => $payout->amountMinor(),
                    'status' => $payout->status()->value,
                ]
            );

            $confirmed = $this->recoveryRepository->markConfirmed(
                $recoveryId,
                $adminId
            );

            $this->db->commit();

            return $confirmed;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function reject(
        string $recoveryId,
        string $adminId,
        ?string $reason = null
    ): array {
        $this->db->begin();

        try {
            $recovery = $this->recoveryRepository->findByIdForUpdate(
                $recoveryId
            );

            if ($recovery === null) {
                throw new DomainException('Payout recovery was not found.');
            }

            if ($recovery['status'] !== 'pending') {
                throw new DomainException(
                    'Only pending payout recoveries can be rejected.'
                );
            }

            $rejected = $this->recoveryRepository->markRejected(
                $recoveryId,
                $adminId,
                $reason
            );

            $this->db->commit();

            return $rejected;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    private function lockPayable(string $payableId): array
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.vendor_payables
                WHERE id = :id
                FOR UPDATE
            SQL,
            ['id' => $payableId]
        );

        $row = $result->fetch();

        if (!is_array($row)) {
            throw new DomainException('Vendor payable was not found.');
        }

        return $row;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}