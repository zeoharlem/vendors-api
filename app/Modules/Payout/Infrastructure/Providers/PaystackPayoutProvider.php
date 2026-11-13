<?php

declare(strict_types=1);

namespace App\Modules\Payout\Infrastructure\Providers;

use App\Modules\Payout\Domain\Contracts\PayoutProviderInterface;
use JsonException;
use RuntimeException;

final class PaystackPayoutProvider implements PayoutProviderInterface
{
    private const BASE_URL = 'https://api.paystack.co';

    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $secretKey = trim($secretKey);

        if ($secretKey === '') {
            throw new RuntimeException(
                'Paystack secret key is not configured.'
            );
        }

        $this->secretKey = $secretKey;
    }

    public function initiateTransfer(
        string $recipientCode,
        int $amountMinor,
        string $currency,
        string $reference,
        string $reason,
    ): array {
        $recipientCode = trim($recipientCode);
        $currency = strtoupper(trim($currency));
        $reference = trim($reference);
        $reason = trim($reason);

        if (
            $recipientCode === ''
            || !preg_match('/^RCP_[A-Za-z0-9]+$/', $recipientCode)
        ) {
            throw new RuntimeException(
                'A valid Paystack transfer recipient code is required.'
            );
        }

        if ($amountMinor <= 0) {
            throw new RuntimeException(
                'Transfer amount must be greater than zero.'
            );
        }

        if ($currency !== 'NGN') {
            throw new RuntimeException(
                'Only NGN transfers are currently supported.'
            );
        }

        if (
            $reference === ''
            || !preg_match('/^[A-Za-z0-9._-]{8,50}$/', $reference)
        ) {
            throw new RuntimeException(
                'Transfer reference must contain 8-50 valid characters.'
            );
        }

        if ($reason === '') {
            throw new RuntimeException(
                'A transfer reason is required.'
            );
        }

        $response = $this->request(
            method: 'POST',
            endpoint: '/transfer',
            payload: [
                'source' => 'balance',
                'amount' => $amountMinor,
                'recipient' => $recipientCode,
                'reason' => mb_substr($reason, 0, 100),
                'reference' => $reference,
                'currency' => $currency,
            ],
        );

        $transfer = $response['data'] ?? null;

        if (!is_array($transfer)) {
            throw new RuntimeException(
                'Paystack returned an invalid transfer response.'
            );
        }

        $returnedReference = $transfer['reference'] ?? null;
        $transferCode = $transfer['transfer_code'] ?? null;
        $transferId = $transfer['id'] ?? null;
        $status = $transfer['status'] ?? null;
        $returnedAmount = $transfer['amount'] ?? null;
        $returnedCurrency = $transfer['currency'] ?? null;

        if (
            !is_string($returnedReference)
            || $returnedReference !== $reference
            || !is_string($transferCode)
            || $transferCode === ''
            || !is_numeric($transferId)
            || !is_numeric($returnedAmount)
            || (int) $returnedAmount !== $amountMinor
            || !is_string($returnedCurrency)
            || strtoupper($returnedCurrency) !== $currency
            || !is_string($status)
            || $status === ''
        ) {
            throw new RuntimeException(
                'Paystack returned an invalid or mismatched transfer response.'
            );
        }

        return [
            'reference' => $returnedReference,
            'provider_reference' => $returnedReference,
            'transfer_code' => $transferCode,
            'transfer_id' => (string) $transferId,
            'provider_transfer_id' => (string) $transferId,
            'amount' => (int) $returnedAmount,
            'currency' => $currency,
            'status' => strtolower($status),
            'provider_response' => $response,
        ];
    }

    /**
     * @throws RuntimeException
     * @throws JsonException
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $payload = null,
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'PHP cURL extension is required for Paystack integration.'
            );
        }

        $curl = curl_init(self::BASE_URL . $endpoint);

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize Paystack HTTP request.'
            );
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            );
        }

        curl_setopt_array($curl, $options);

        try {
            $rawResponse = curl_exec($curl);

            if ($rawResponse === false) {
                throw new RuntimeException(
                    'Paystack connection failed: ' . curl_error($curl)
                );
            }

            $httpStatus = (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );
        } finally {
            curl_close($curl);
        }

        $decoded = json_decode(
            $rawResponse,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Invalid response received from Paystack.'
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = $decoded['message'] ?? 'Paystack request failed.';

            throw new RuntimeException(
                sprintf(
                    'Paystack API error (%d): %s',
                    $httpStatus,
                    (string) $message
                )
            );
        }

        if (($decoded['status'] ?? false) !== true) {
            throw new RuntimeException(
                (string) (
                    $decoded['message']
                    ?? 'Paystack transfer request was unsuccessful.'
                )
            );
        }

        return $decoded;
    }

    /**
     * Retrieve Nigerian banks supported by Paystack.
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
        $response = $this->request(
            method: 'GET',
            endpoint: '/bank?' . http_build_query([
                'country' => 'nigeria',
                'currency' => 'NGN',
                'perPage' => 100,
            ])
        );

        $banks = $response['data'] ?? null;

        if (!is_array($banks)) {
            throw new RuntimeException(
                'Paystack returned an invalid bank list.'
            );
        }

        $result = [];

        foreach ($banks as $bank) {
            if (!is_array($bank)) {
                continue;
            }

            $name = trim((string) ($bank['name'] ?? ''));
            $code = trim((string) ($bank['code'] ?? ''));

            if ($name === '' || $code === '') {
                continue;
            }

            $result[] = [
                'name' => $name,
                'code' => $code,
                'country' => 'Nigeria',
                'currency' => 'NGN',
            ];
        }

        return $result;
    }

    /**
     * Resolve a Nigerian bank account number.
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
        $accountNumber = trim($accountNumber);
        $bankCode = trim($bankCode);

        if (!preg_match('/^\d{10}$/', $accountNumber)) {
            throw new RuntimeException(
                'Nigerian bank account numbers must contain exactly 10 digits.'
            );
        }

        if ($bankCode === '') {
            throw new RuntimeException(
                'A valid bank code is required.'
            );
        }

        $response = $this->request(
            method: 'GET',
            endpoint: '/bank/resolve?' . http_build_query([
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ])
        );

        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            throw new RuntimeException(
                'Paystack returned an invalid account resolution response.'
            );
        }

        $resolvedAccountNumber = trim(
            (string) ($data['account_number'] ?? '')
        );

        $accountName = trim(
            (string) ($data['account_name'] ?? '')
        );

        if (
            $resolvedAccountNumber !== $accountNumber
            || $accountName === ''
        ) {
            throw new RuntimeException(
                'Paystack could not verify the supplied bank account.'
            );
        }

        return [
            'account_number' => $resolvedAccountNumber,
            'account_name' => $accountName,
        ];
    }

    /**
     * Create a Paystack transfer recipient after account resolution.
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
        $accountName = trim($accountName);
        $accountNumber = trim($accountNumber);
        $bankCode = trim($bankCode);

        if ($accountName === '') {
            throw new RuntimeException(
                'Verified account name is required.'
            );
        }

        if (!preg_match('/^\d{10}$/', $accountNumber)) {
            throw new RuntimeException(
                'A valid 10-digit Nigerian account number is required.'
            );
        }

        if ($bankCode === '') {
            throw new RuntimeException(
                'A valid bank code is required.'
            );
        }

        $response = $this->request(
            method: 'POST',
            endpoint: '/transferrecipient',
            payload: [
                'type' => 'nuban',
                'name' => $accountName,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'currency' => 'NGN',
            ]
        );

        $data = $response['data'] ?? null;

        if (!is_array($data)) {
            throw new RuntimeException(
                'Paystack returned an invalid transfer recipient response.'
            );
        }

        $recipientCode = trim(
            (string) ($data['recipient_code'] ?? '')
        );

        $details = $data['details'] ?? null;

        if (!is_array($details)) {
            throw new RuntimeException(
                'Paystack returned incomplete recipient bank details.'
            );
        }

        $returnedAccountNumber = trim(
            (string) ($details['account_number'] ?? '')
        );

        $returnedBankCode = trim(
            (string) ($details['bank_code'] ?? '')
        );

        $returnedBankName = trim(
            (string) ($details['bank_name'] ?? '')
        );

        $returnedAccountName = trim(
            (string) (
                $details['account_name']
                ?? $data['name']
                ?? ''
            )
        );

        if (
            !preg_match('/^RCP_[A-Za-z0-9]+$/', $recipientCode)
            || $returnedAccountNumber !== $accountNumber
            || $returnedBankCode !== $bankCode
            || $returnedBankName === ''
            || $returnedAccountName === ''
            || ($data['active'] ?? true) !== true
            || strtoupper((string) ($data['currency'] ?? '')) !== 'NGN'
        ) {
            throw new RuntimeException(
                'Paystack returned a recipient that does not match the verified bank account.'
            );
        }

        return [
            'recipient_code' => $recipientCode,
            'account_name' => $returnedAccountName,
            'account_number' => $returnedAccountNumber,
            'bank_code' => $returnedBankCode,
            'bank_name' => $returnedBankName,
            'currency' => 'NGN',
        ];
    }
}
