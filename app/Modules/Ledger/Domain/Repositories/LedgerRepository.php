<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Repositories;

interface LedgerRepository
{
    /**
     * Find an existing ledger transaction by its idempotency key.
     */
    public function findTransactionByKey(string $transactionKey): ?array;

    /**
     * Find a ledger account by its unique code.
     */
    public function findAccountByCode(string $code): ?array;

    /**
     * Create a ledger account if it does not already exist.
     *
     * Implementations must safely handle concurrent account creation.
     */
    public function createAccountIfNotExists(array $accountData): array;

    /**
     * Create a ledger transaction.
     *
     * The transaction_key must be unique.
     */
    public function createTransaction(array $transactionData): array;

    /**
     * Create a debit or credit entry for a ledger transaction.
     */
    public function createEntry(array $entryData): void;

    /**
     * Mark a ledger transaction as posted.
     */
    public function markTransactionPosted(string $transactionId): void;
}