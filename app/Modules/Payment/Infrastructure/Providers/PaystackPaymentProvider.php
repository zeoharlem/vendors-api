<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Providers;

use App\Modules\Payment\Domain\Contracts\PaymentProviderInterface;
use RuntimeException;
use JsonException;

final class PaystackPaymentProvider implements PaymentProviderInterface
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

    public function initializePayment(array $data): array
    {
        $email = trim((string) ($data['email'] ?? ''));
        $amount = (int) ($data['amount'] ?? 0);
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'NGN')));
        $reference = trim((string) ($data['reference'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid customer email is required.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        if ($currency !== 'NGN') {
            throw new RuntimeException('Only NGN payments are currently supported.');
        }

        if (
            $reference === ''
            || !preg_match('/^[A-Za-z0-9.\-=]+$/', $reference)
        ) {
            throw new RuntimeException('Invalid payment reference.');
        }

        $payload = [
            'email' => $email,
            'amount' => (string) $amount,
            'currency' => $currency,
            'reference' => $reference,
        ];

        if (!empty($data['callback_url'])) {
            $payload['callback_url'] = $data['callback_url'];
        }

        if (!empty($data['metadata'])) {
            $payload['metadata'] = json_encode(
                $data['metadata'],
                JSON_THROW_ON_ERROR
            );
        }

        $response = $this->request(
            method: 'POST',
            endpoint: '/transaction/initialize',
            payload: $payload
        );

        $responseData = $response['data'] ?? [];

        $authorizationUrl = $responseData['authorization_url'] ?? null;
        $accessCode = $responseData['access_code'] ?? null;
        $returnedReference = $responseData['reference'] ?? null;

        if (
            !is_string($authorizationUrl)
            || !filter_var($authorizationUrl, FILTER_VALIDATE_URL)
            || !is_string($accessCode)
            || !is_string($returnedReference)
        ) {
            throw new RuntimeException(
                'Paystack returned an invalid initialization response.'
            );
        }

        if ($returnedReference !== $reference) {
            throw new RuntimeException(
                'Paystack returned an unexpected payment reference.'
            );
        }

        return [
            'authorization_url' => $authorizationUrl,
            'access_code' => $accessCode,
            'reference' => $returnedReference,
            'provider_response' => $response,
        ];
    }

    public function verifyPayment(string $reference): array
    {
        $reference = trim($reference);

        if (
            $reference === ''
            || !preg_match('/^[A-Za-z0-9.\-=]+$/', $reference)
        ) {
            throw new RuntimeException('Invalid payment reference.');
        }

        $response = $this->request(
            method: 'GET',
            endpoint: '/transaction/verify/' . rawurlencode($reference)
        );

        $transaction = $response['data'] ?? [];

        $returnedReference = $transaction['reference'] ?? null;
        $amount = $transaction['amount'] ?? null;
        $currency = $transaction['currency'] ?? null;
        $status = $transaction['status'] ?? null;

        if (
            !is_string($returnedReference)
            || !is_numeric($amount)
            || !is_string($currency)
            || !is_string($status)
        ) {
            throw new RuntimeException(
                'Paystack returned an invalid verification response.'
            );
        }

        if ($returnedReference !== $reference) {
            throw new RuntimeException(
                'Paystack verification reference does not match.'
            );
        }

        $transactionId = $transaction['id'] ?? null;

        return [
            'reference' => $returnedReference,
            'amount' => (int) $amount,
            'currency' => strtoupper($currency),
            'status' => strtolower($status),
            'provider_transaction_id' => $transactionId !== null
                ? (string) $transactionId
                : null,
            'provider_response' => $response,
        ];
    }

    /**
     * Send an authenticated request to Paystack.
     *
     * @throws RuntimeException
     * @throws JsonException
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $payload = null
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

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
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
                (string) ($decoded['message'] ?? 'Paystack request was unsuccessful.')
            );
        }

        return $decoded;
    }
}