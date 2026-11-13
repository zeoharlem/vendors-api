<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Contracts;

interface PayoutProviderInterface
{
    /**
     * @return array<int, array{
     *     name: string,
     *     code: string,
     *     country: string,
     *     currency: string
     * }>
     */
    public function listBanks(): array;

    /**
     * @return array{
     *     account_number: string,
     *     account_name: string
     * }
     */
    public function resolveBankAccount(
        string $accountNumber,
        string $bankCode
    ): array;

    /**
     * @return array{
     *     recipient_code: string,
     *     account_name: string,
     *     account_number: string,
     *     bank_code: string,
     *     bank_name: string,
     *     currency: string
     * }
     */
    public function createTransferRecipient(
        string $accountName,
        string $accountNumber,
        string $bankCode
    ): array;

    /**
     * @return array{
     *     reference: string,
     *     provider_reference: string,
     *     transfer_code: string,
     *     transfer_id: string,
     *     provider_transfer_id: string,
     *     amount: int,
     *     currency: string,
     *     status: string,
     *     provider_response: array
     * }
     */
    public function initiateTransfer(
        string $recipientCode,
        int $amountMinor,
        string $currency,
        string $reference,
        string $reason
    ): array;
}