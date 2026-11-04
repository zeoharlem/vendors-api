<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Persistence;

use App\Modules\Ledger\Domain\Repositories\LedgerRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoLedgerRepository implements LedgerRepository
{
    public function __construct(
        private readonly AdapterInterface $db
    ) {
    }

    public function findTransactionByKey(string $transactionKey): ?array
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.ledger_transactions
                WHERE transaction_key = :transaction_key
                LIMIT 1
            SQL,
            [
                'transaction_key' => $transactionKey,
            ]
        );

        $row = $result->fetch();

        return $row ?: null;
    }

    public function findAccountByCode(string $code): ?array
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.ledger_accounts
                WHERE code = :code
                LIMIT 1
            SQL,
            [
                'code' => $code,
            ]
        );

        $row = $result->fetch();

        return $row ?: null;
    }

    public function createAccountIfNotExists(array $accountData): array
    {
        foreach (['code', 'name', 'account_type'] as $field) {
            if (
                !isset($accountData[$field])
                || trim((string) $accountData[$field]) === ''
            ) {
                throw new RuntimeException(
                    "Missing required ledger account field: {$field}"
                );
            }
        }

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.ledger_accounts (
                    code,
                    name,
                    account_type,
                    currency,
                    owner_user_id,
                    store_id,
                    is_active
                )
                VALUES (
                    :code,
                    :name,
                    :account_type,
                    :currency,
                    :owner_user_id,
                    :store_id,
                    :is_active
                )
                ON CONFLICT (code) DO NOTHING
                RETURNING *
            SQL,
            [
                'code' => $accountData['code'],
                'name' => $accountData['name'],
                'account_type' => $accountData['account_type'],
                'currency' => $accountData['currency'] ?? 'NGN',
                'owner_user_id' => $accountData['owner_user_id'] ?? null,
                'store_id' => $accountData['store_id'] ?? null,
                'is_active' => $accountData['is_active'] ?? true,
            ]
        );

        $account = $result->fetch();

        if ($account) {
            return $account;
        }

        $existingAccount = $this->findAccountByCode(
            (string) $accountData['code']
        );

        if (!$existingAccount) {
            throw new RuntimeException(
                'Unable to create or retrieve ledger account.'
            );
        }

        return $existingAccount;
    }

    public function createTransaction(array $transactionData): array
    {
        foreach (['transaction_key', 'transaction_type'] as $field) {
            if (
                !isset($transactionData[$field])
                || trim((string) $transactionData[$field]) === ''
            ) {
                throw new RuntimeException(
                    "Missing required ledger transaction field: {$field}"
                );
            }
        }

        $metadata = json_encode(
            $transactionData['metadata'] ?? [],
            JSON_THROW_ON_ERROR
        );

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.ledger_transactions (
                    transaction_key,
                    transaction_type,
                    order_id,
                    payment_id,
                    vendor_order_id,
                    payout_id,
                    refund_id,
                    currency,
                    description,
                    metadata,
                    status
                )
                VALUES (
                    :transaction_key,
                    :transaction_type,
                    :order_id,
                    :payment_id,
                    :vendor_order_id,
                    :payout_id,
                    :refund_id,
                    :currency,
                    :description,
                    CAST(:metadata AS JSONB),
                    'pending'
                )
                ON CONFLICT (transaction_key) DO NOTHING
                RETURNING *
            SQL,
            [
                'transaction_key' => $transactionData['transaction_key'],
                'transaction_type' => $transactionData['transaction_type'],
                'order_id' => $transactionData['order_id'] ?? null,
                'payment_id' => $transactionData['payment_id'] ?? null,
                'vendor_order_id' => $transactionData['vendor_order_id'] ?? null,
                'payout_id' => $transactionData['payout_id'] ?? null,
                'refund_id' => $transactionData['refund_id'] ?? null,
                'currency' => $transactionData['currency'] ?? 'NGN',
                'description' => $transactionData['description'] ?? null,
                'metadata' => $metadata,
            ]
        );

        $transaction = $result->fetch();

        if ($transaction) {
            return $transaction;
        }

        $existingTransaction = $this->findTransactionByKey(
            (string) $transactionData['transaction_key']
        );

        if (!$existingTransaction) {
            throw new RuntimeException(
                'Unable to create or retrieve ledger transaction.'
            );
        }

        return $existingTransaction;
    }

    /**public function createEntry(array $entryData): void
    {
        foreach (
            [
                'ledger_transaction_id',
                'ledger_account_id',
                'entry_side',
                'amount_minor',
            ] as $field
        ) {
            if (!array_key_exists($field, $entryData)) {
                throw new RuntimeException(
                    "Missing required ledger entry field: {$field}"
                );
            }
        }

        $entrySide = strtoupper((string) $entryData['entry_side']);
        $amountMinor = (int) $entryData['amount_minor'];

        if (!in_array($entrySide, ['D', 'C'], true)) {
            throw new RuntimeException(
                'Ledger entry side must be D or C.'
            );
        }

        if ($amountMinor <= 0) {
            throw new RuntimeException(
                'Ledger entry amount must be greater than zero.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.ledger_entries (
                    ledger_transaction_id,
                    ledger_account_id,
                    entry_side,
                    amount_minor,
                    currency,
                    description
                )
                SELECT
                    lt.id,
                    la.id,
                    :entry_side,
                    :amount_minor,
                    :currency,
                    :description
                FROM public.ledger_transactions lt
                INNER JOIN public.ledger_accounts la
                    ON la.id = :ledger_account_id
                WHERE lt.id = :ledger_transaction_id
                AND lt.status = 'pending'
                AND la.is_active = TRUE
                RETURNING id
            SQL,
            [
                'ledger_transaction_id' => $entryData['ledger_transaction_id'],
                'ledger_account_id' => $entryData['ledger_account_id'],
                'entry_side' => $entrySide,
                'amount_minor' => $amountMinor,
                'currency' => $entryData['currency'] ?? 'NGN',
                'description' => $entryData['description'] ?? null,
            ]
        );

        $inserted = $result->fetch();

        if (!$inserted) {
            throw new RuntimeException(
                'Ledger entry could not be created. '
                . 'Ensure the transaction is pending and the account is active.'
            );
        }
    }**/

    public function createEntry(array $entryData): void
    {
        foreach (
            [
                'ledger_transaction_id',
                'ledger_account_id',
                'entry_side',
                'amount_minor',
            ] as $field
        ) {
            if (!array_key_exists($field, $entryData)) {
                throw new RuntimeException(
                    "Missing required ledger entry field: {$field}"
                );
            }
        }

        $entrySide = strtoupper((string) $entryData['entry_side']);
        $amountMinor = (int) $entryData['amount_minor'];
        $currency = strtoupper(
            trim((string) ($entryData['currency'] ?? 'NGN'))
        );

        if (!in_array($entrySide, ['D', 'C'], true)) {
            throw new RuntimeException(
                'Ledger entry side must be D or C.'
            );
        }

        if ($amountMinor <= 0) {
            throw new RuntimeException(
                'Ledger entry amount must be greater than zero.'
            );
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException(
                'Ledger entry currency must be a valid three-letter currency code.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.ledger_entries (
                    ledger_transaction_id,
                    ledger_account_id,
                    entry_side,
                    amount_minor,
                    currency,
                    description
                )
                SELECT
                    lt.id,
                    la.id,
                    :entry_side,
                    :amount_minor,
                    :currency,
                    :description
                FROM public.ledger_transactions lt
                INNER JOIN public.ledger_accounts la
                    ON la.id = :ledger_account_id
                WHERE lt.id = :ledger_transaction_id
                AND lt.status = 'pending'
                AND la.is_active = TRUE
                AND lt.currency = :currency
                AND la.currency = :currency
                RETURNING id
            SQL,
            [
                'ledger_transaction_id' => $entryData['ledger_transaction_id'],
                'ledger_account_id' => $entryData['ledger_account_id'],
                'entry_side' => $entrySide,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'description' => $entryData['description'] ?? null,
            ]
        );

        $inserted = $result->fetch();

        if (!$inserted) {
            throw new RuntimeException(
                'Ledger entry could not be created. '
                . 'Verify that the transaction is pending, '
                . 'the account is active, and the currencies match.'
            );
        }
    }

    public function markTransactionPosted(string $transactionId): void
    {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.ledger_transactions lt
                SET
                    status = 'posted',
                    posted_at = COALESCE(posted_at, CURRENT_TIMESTAMP)
                WHERE lt.id = :transaction_id
                AND lt.status = 'pending'
                AND EXISTS (
                    SELECT 1
                    FROM public.ledger_entries le
                    WHERE le.ledger_transaction_id = lt.id
                )
                AND (
                    SELECT COALESCE(
                        SUM(
                            CASE
                                WHEN le.entry_side = 'D'
                                THEN le.amount_minor
                                ELSE 0
                            END
                        ),
                        0
                    )
                    FROM public.ledger_entries le
                    WHERE le.ledger_transaction_id = lt.id
                ) = (
                    SELECT COALESCE(
                        SUM(
                            CASE
                                WHEN le.entry_side = 'C'
                                THEN le.amount_minor
                                ELSE 0
                            END
                        ),
                        0
                    )
                    FROM public.ledger_entries le
                    WHERE le.ledger_transaction_id = lt.id
                )
                AND (
                    SELECT COUNT(*)
                    FROM public.ledger_entries le
                    WHERE le.ledger_transaction_id = lt.id
                        AND le.entry_side = 'D'
                ) > 0
                AND (
                    SELECT COUNT(*)
                    FROM public.ledger_entries le
                    WHERE le.ledger_transaction_id = lt.id
                        AND le.entry_side = 'C'
                ) > 0
                RETURNING lt.id
            SQL,
            [
                'transaction_id' => $transactionId,
            ]
        );

        $posted = $result->fetch();

        if (!$posted) {
            throw new RuntimeException(
                'Ledger transaction could not be posted. '
                . 'Ensure it has balanced debit and credit entries.'
            );
        }
    }
}
