<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Providers;

use App\Modules\Payout\Domain\Contracts\PayoutProviderInterface;
use InvalidArgumentException;

final class MockPayoutProvider implements PayoutProviderInterface
{
    /**
     * Sample Nigerian banks for local development.
     *
     * @return array<int, array{
     *     name: string,
     *     code: string,
     *     country: string,
     *     currency: string
     * }>
     */
    public function listBanks(): array
    {
        return [
            [
                'name' => 'Access Bank',
                'code' => '044',
                'country' => 'Nigeria',
                'currency' => 'NGN',
            ],
            [
                'name' => 'First Bank of Nigeria',
                'code' => '011',
                'country' => 'Nigeria',
                'currency' => 'NGN',
            ],
            [
                'name' => 'United Bank for Africa',
                'code' => '033',
                'country' => 'Nigeria',
                'currency' => 'NGN',
            ],
            [
                'name' => 'Guaranty Trust Bank',
                'code' => '058',
                'country' => 'Nigeria',
                'currency' => 'NGN',
            ],
            [
                'name' => 'Zenith Bank',
                'code' => '057',
                'country' => 'Nigeria',
                'currency' => 'NGN',
            ],
        ];
    }

    /**
     * Simulates successful bank account resolution.
     *
     * @return array{
     *     account_number: string,
     *     account_name: string
     * }
     */
    public function resolveBankAccount(
        string $accountNumber,
        string $bankCode
    ): array {
        $this->validateAccountNumber($accountNumber);
        $this->validateBankCode($bankCode);

        return [
            'account_number' => $accountNumber,
            'account_name' => 'MOCK VENDOR ACCOUNT',
        ];
    }

    /**
     * Simulates creating a Paystack transfer recipient.
     *
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
    ): array {
        $this->validateAccountNumber($accountNumber);
        $this->validateBankCode($bankCode);

        if (trim($accountName) === '') {
            throw new InvalidArgumentException(
                'A valid account name is required.'
            );
        }

        $bank = $this->findBank($bankCode);

        if ($bank === null) {
            throw new InvalidArgumentException(
                'The selected mock bank does not exist.'
            );
        }

        $recipientCode = 'RCP_MOCK_' . strtoupper(
            substr(
                hash('sha256', $bankCode . ':' . $accountNumber),
                0,
                16
            )
        );

        return [
            'recipient_code' => $recipientCode,
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => $bank['name'],
            'currency' => 'NGN',
        ];
    }

    /**
     * Simulates initiating a bank transfer.
     *
     * The transfer remains pending until a simulated webhook
     * is processed by the existing transfer webhook service.
     *
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
    ): array {
        if (!str_starts_with($recipientCode, 'RCP_MOCK_')) {
            throw new InvalidArgumentException(
                'Invalid mock recipient code.'
            );
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException(
                'Transfer amount must be greater than zero.'
            );
        }

        if (strtoupper($currency) !== 'NGN') {
            throw new InvalidArgumentException(
                'Only NGN transfers are supported by the mock provider.'
            );
        }

        if (trim($reference) === '') {
            throw new InvalidArgumentException(
                'A transfer reference is required.'
            );
        }

        $transferId = (string) hexdec(
            substr(hash('sha256', $reference), 0, 7)
        );

        $transferCode = 'TRF_MOCK_' . strtoupper(
            substr(hash('sha256', $reference), 0, 16)
        );

        $response = [
            'id' => $transferId,
            'reference' => $reference,
            'transfer_code' => $transferCode,
            'recipient' => $recipientCode,
            'amount' => $amountMinor,
            'currency' => 'NGN',
            'status' => 'pending',
            'reason' => $reason,
        ];

        return [
            'reference' => $reference,
            'provider_reference' => $reference,
            'transfer_code' => $transferCode,
            'transfer_id' => $transferId,
            'provider_transfer_id' => $transferId,
            'amount' => $amountMinor,
            'currency' => 'NGN',
            'status' => 'pending',
            'provider_response' => $response,
        ];
    }

    private function validateAccountNumber(string $accountNumber): void
    {
        if (!preg_match('/^\d{10}$/', $accountNumber)) {
            throw new InvalidArgumentException(
                'A valid 10-digit Nigerian bank account number is required.'
            );
        }
    }

    private function validateBankCode(string $bankCode): void
    {
        if ($this->findBank($bankCode) === null) {
            throw new InvalidArgumentException(
                'The selected mock bank code is invalid.'
            );
        }
    }

    /**
     * @return array{
     *     name: string,
     *     code: string,
     *     country: string,
     *     currency: string
     * }|null
     */
    private function findBank(string $bankCode): ?array
    {
        foreach ($this->listBanks() as $bank) {
            if ($bank['code'] === $bankCode) {
                return $bank;
            }
        }

        return null;
    }
}
