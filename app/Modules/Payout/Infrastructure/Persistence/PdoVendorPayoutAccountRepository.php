<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Persistence;

use App\Modules\Payout\Domain\Repositories\VendorPayoutAccountRepositoryInterface;
use DomainException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Throwable;

final class PdoVendorPayoutAccountRepository implements VendorPayoutAccountRepositoryInterface
{
    public function __construct(
        private readonly AdapterInterface $db
    ) {
    }

    public function listForStore(string $storeId): array
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                id,
                store_id,
                provider,
                recipient_code,
                bank_code,
                bank_name,
                account_name,
                account_number_last4,
                currency,
                status,
                is_default,
                created_at,
                updated_at
            FROM public.vendor_payout_accounts
            WHERE store_id = :store_id
            ORDER BY is_default DESC, created_at DESC
            SQL,
            ['store_id' => $storeId]
        );

        return $result->fetchAll();
    }

    public function findByIdForStore(
        string $accountId,
        string $storeId
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                id,
                store_id,
                provider,
                recipient_code,
                bank_code,
                bank_name,
                account_name,
                account_number_last4,
                currency,
                status,
                is_default,
                created_at,
                updated_at
            FROM public.vendor_payout_accounts
            WHERE id = :account_id
              AND store_id = :store_id
            LIMIT 1
            SQL,
            [
                'account_id' => $accountId,
                'store_id' => $storeId,
            ]
        );

        $row = $result->fetch();

        return $row ? $this->normalizeRow($row) : null;
    }

    public function create(
        string $storeId,
        string $recipientCode,
        string $bankCode,
        string $bankName,
        string $accountName,
        string $accountNumberLast4,
        string $currency,
        bool $makeDefault
    ): array {
        $this->validateAccountDetails(
            $recipientCode,
            $bankCode,
            $bankName,
            $accountName,
            $accountNumberLast4,
            $currency
        );

        $this->db->begin();

        try {
            $this->lockStore($storeId);

            // Prevent registering the same Paystack recipient twice.
            $existing = $this->db->query(
                <<<'SQL'
                    SELECT id
                    FROM public.vendor_payout_accounts
                    WHERE store_id = :store_id
                    AND provider = 'paystack'
                    AND recipient_code = :recipient_code
                    LIMIT 1
                SQL,
                [
                    'store_id' => $storeId,
                    'recipient_code' => $recipientCode,
                ]
            )->fetch();

            if ($existing) {
                throw new DomainException(
                    'This payout recipient is already registered for the store.'
                );
            }

            if ($makeDefault) {
                $this->clearDefaultAccounts($storeId, $currency);
            }

            $result = $this->db->query(
                <<<'SQL'
                INSERT INTO public.vendor_payout_accounts (
                    store_id,
                    provider,
                    recipient_code,
                    bank_code,
                    bank_name,
                    account_name,
                    account_number_last4,
                    currency,
                    status,
                    is_default,
                    created_at,
                    updated_at
                )
                VALUES (
                    :store_id,
                    'paystack',
                    :recipient_code,
                    :bank_code,
                    :bank_name,
                    :account_name,
                    :account_number_last4,
                    :currency,
                    'active',
                    :is_default,
                    NOW(),
                    NOW()
                )
                RETURNING
                    id,
                    store_id,
                    provider,
                    recipient_code,
                    bank_code,
                    bank_name,
                    account_name,
                    account_number_last4,
                    currency,
                    status,
                    is_default,
                    created_at,
                    updated_at
                SQL,
                [
                    'store_id' => $storeId,
                    'recipient_code' => $recipientCode,
                    'bank_code' => $bankCode,
                    'bank_name' => $bankName,
                    'account_name' => $accountName,
                    'account_number_last4' => $accountNumberLast4,
                    'currency' => $currency,
                    'is_default' => $makeDefault,
                ]
            );

            $account = $result->fetch();

            if (!$account) {
                throw new DomainException(
                    'The payout account could not be created.'
                );
            }

            $this->db->commit();

            return $account ? $this->normalizeRow($account) : [];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    public function setDefault(
        string $accountId,
        string $storeId
    ): array {
        $this->db->begin();

        try {
            $this->lockStore($storeId);

            $account = $this->findByIdForStore($accountId, $storeId);

            if ($account === null) {
                throw new DomainException('Payout account not found.');
            }

            if ($account['status'] !== 'active') {
                throw new DomainException(
                    'A disabled payout account cannot be made the default.'
                );
            }

            $currency = strtoupper(trim((string) $account['currency']));

            $this->clearDefaultAccounts($storeId, $currency);

            $result = $this->db->query(
                <<<'SQL'
                UPDATE public.vendor_payout_accounts
                SET
                    is_default = TRUE,
                    updated_at = NOW()
                WHERE id = :account_id
                  AND store_id = :store_id
                  AND status = 'active'
                RETURNING
                    id,
                    store_id,
                    provider,
                    recipient_code,
                    bank_code,
                    bank_name,
                    account_name,
                    account_number_last4,
                    currency,
                    status,
                    is_default,
                    created_at,
                    updated_at
                SQL,
                [
                    'account_id' => $accountId,
                    'store_id' => $storeId,
                ]
            );

            $updated = $result->fetch();

            if (!$updated) {
                throw new DomainException(
                    'The default payout account could not be updated.'
                );
            }

            $this->db->commit();

            return $updated ? $this->normalizeRow($updated) : [];
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    public function disable(string $accountId, string $storeId): void
    {
        $this->db->begin();

        try {
            $this->lockStore($storeId);

            $account = $this->findByIdForStore($accountId, $storeId);

            if ($account === null) {
                throw new DomainException('Payout account not found.');
            }

            if ($account['status'] === 'disabled') {
                $this->db->commit();
                return;
            }

            $this->db->query(
                <<<'SQL'
                UPDATE public.vendor_payout_accounts
                SET
                    status = 'disabled',
                    is_default = FALSE,
                    updated_at = NOW()
                WHERE id = :account_id
                  AND store_id = :store_id
                SQL,
                [
                    'account_id' => $accountId,
                    'store_id' => $storeId,
                ]
            );

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();

            throw $exception;
        }
    }

    private function lockStore(string $storeId): void
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT id
            FROM public.stores
            WHERE id = :store_id
            FOR UPDATE
            SQL,
            ['store_id' => $storeId]
        );

        if (!$result->fetch()) {
            throw new DomainException('Store not found.');
        }
    }

    private function clearDefaultAccounts(
        string $storeId,
        string $currency
    ): void {
        $this->db->query(
            <<<'SQL'
            UPDATE public.vendor_payout_accounts
            SET
                is_default = FALSE,
                updated_at = NOW()
            WHERE store_id = :store_id
              AND currency = :currency
              AND status = 'active'
              AND is_default = TRUE
            SQL,
            [
                'store_id' => $storeId,
                'currency' => $currency,
            ]
        );
    }

    private function validateAccountDetails(
        string $recipientCode,
        string $bankCode,
        string $bankName,
        string $accountName,
        string $accountNumberLast4,
        string $currency
    ): void {
        if (
            trim($recipientCode) === ''
            || trim($bankCode) === ''
            || trim($bankName) === ''
            || trim($accountName) === ''
        ) {
            throw new DomainException(
                'Verified payout account details are incomplete.'
            );
        }

        if (!preg_match('/^[0-9]{4}$/', $accountNumberLast4)) {
            throw new DomainException(
                'The account number must be represented by its last four digits.'
            );
        }

        if (strtoupper(trim($currency)) !== 'NGN') {
            throw new DomainException(
                'Only NGN payout accounts are currently supported.'
            );
        }
    }

    private function normalizeRow(array $row): array
    {
        return array_filter(
            $row,
            static fn(mixed $value, int|string $key): bool =>
                !is_int($key),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
