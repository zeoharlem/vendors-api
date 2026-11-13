<?php

declare(strict_types=1);

namespace App\Modules\Payout\Application\Services;

use App\Modules\Payout\Domain\Contracts\PayoutProviderInterface;
use App\Modules\Payout\Domain\Repositories\VendorPayoutAccountRepositoryInterface;
use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use DomainException;

final class PayoutAccountService
{
    public function __construct(
        private readonly StoreAccessCheckerInterface $storeAccessChecker,
        private readonly PayoutProviderInterface $payoutProvider,
        private readonly VendorPayoutAccountRepositoryInterface $accountRepository
    ) {
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     code: string,
     *     country: string,
     *     currency: string
     * }>
     */
    public function listBanks(): array
    {
        return $this->payoutProvider->listBanks();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(
        string $userId,
        string $storeId
    ): array {
        $this->assertStoreAccess($userId, $storeId);

        return $this->accountRepository->listForStore($storeId);
    }

    /**
     * Resolve the bank account with Paystack, create a transfer recipient,
     * then persist only the verified account details.
     *
     * @return array<string, mixed>
     */
    public function registerAccount(
        string $userId,
        string $storeId,
        string $accountNumber,
        string $bankCode,
        bool $makeDefault = false
    ): array {
        $this->assertStoreAccess($userId, $storeId);

        $accountNumber = trim($accountNumber);
        $bankCode = trim($bankCode);

        if (!preg_match('/^[0-9]{10}$/', $accountNumber)) {
            throw new DomainException(
                'A valid 10-digit Nigerian bank account number is required.'
            );
        }

        if ($bankCode === '') {
            throw new DomainException('A bank code is required.');
        }

        $resolved = $this->payoutProvider->resolveBankAccount(
            $accountNumber,
            $bankCode
        );

        $resolvedAccountNumber = trim(
            (string) ($resolved['account_number'] ?? '')
        );

        $resolvedAccountName = trim(
            (string) ($resolved['account_name'] ?? '')
        );

        if (
            $resolvedAccountNumber !== $accountNumber
            || $resolvedAccountName === ''
        ) {
            throw new DomainException(
                'The bank account could not be verified.'
            );
        }

        $recipient = $this->payoutProvider->createTransferRecipient(
            $resolvedAccountName,
            $accountNumber,
            $bankCode
        );

        $recipientCode = trim(
            (string) ($recipient['recipient_code'] ?? '')
        );

        $recipientAccountNumber = trim(
            (string) ($recipient['account_number'] ?? '')
        );

        $recipientBankCode = trim(
            (string) ($recipient['bank_code'] ?? '')
        );

        $recipientAccountName = trim(
            (string) ($recipient['account_name'] ?? '')
        );

        $recipientBankName = trim(
            (string) ($recipient['bank_name'] ?? '')
        );

        $currency = strtoupper(
            trim((string) ($recipient['currency'] ?? ''))
        );

        if (
            $recipientCode === ''
            || $recipientAccountNumber !== $accountNumber
            || $recipientBankCode !== $bankCode
            || $recipientAccountName === ''
            || $recipientBankName === ''
            || $currency !== 'NGN'
        ) {
            throw new DomainException(
                'Paystack returned incomplete or mismatched recipient details.'
            );
        }

        $accounts = $this->accountRepository->listForStore($storeId);

        $hasDefault = false;

        foreach ($accounts as $account) {
            if (
                ($account['status'] ?? null) === 'active'
                && filter_var(
                    $account['is_default'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                )
                && strtoupper((string) ($account['currency'] ?? '')) === 'NGN'
            ) {
                $hasDefault = true;
                break;
            }
        }

        $shouldBeDefault = $makeDefault || !$hasDefault;

        return $this->accountRepository->create(
            storeId: $storeId,
            recipientCode: $recipientCode,
            bankCode: $bankCode,
            bankName: $recipientBankName,
            accountName: $recipientAccountName,
            accountNumberLast4: substr($accountNumber, -4),
            currency: $currency,
            makeDefault: $shouldBeDefault
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefault(
        string $userId,
        string $storeId,
        string $accountId
    ): array {
        $this->assertStoreAccess($userId, $storeId);

        return $this->accountRepository->setDefault(
            $accountId,
            $storeId
        );
    }

    public function disableAccount(
        string $userId,
        string $storeId,
        string $accountId
    ): void {
        $this->assertStoreAccess($userId, $storeId);

        $this->accountRepository->disable(
            $accountId,
            $storeId
        );
    }

    private function assertStoreAccess(
        string $userId,
        string $storeId
    ): void {
        if (
            trim($userId) === ''
            || trim($storeId) === ''
            || !$this->storeAccessChecker->canManageStore($userId, $storeId)
        ) {
            throw new DomainException(
                'You are not authorized to manage payout accounts for this store.'
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
