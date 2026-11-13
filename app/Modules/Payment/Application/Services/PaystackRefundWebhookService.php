<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use InvalidArgumentException;
use RuntimeException;

final readonly class PaystackRefundWebhookService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
    ) {
    }

    public function handle(array $event): array
    {
        $eventType = trim((string) ($event['event'] ?? ''));
        $data = $event['data'] ?? null;

        if (!is_array($data)) {
            throw new InvalidArgumentException(
                'Paystack refund webhook data is invalid.'
            );
        }

        $status = match ($eventType) {
            'refund.pending' => 'pending',
            'refund.processing' => 'processing',
            'refund.needs-attention' => 'needs_attention',
            'refund.failed' => 'failed',
            'refund.processed' => 'success',
            default => throw new InvalidArgumentException(
                'Unsupported Paystack refund event.'
            ),
        };

        $transactionReference = trim(
            (string) ($data['transaction_reference'] ?? '')
        );

        $providerReference = isset($data['refund_reference'])
            ? trim((string) $data['refund_reference'])
            : null;

        if ($providerReference === '') {
            $providerReference = null;
        }

        $amountMinor = filter_var(
            $data['amount'] ?? null,
            FILTER_VALIDATE_INT
        );

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));

        if (
            $transactionReference === ''
            || $amountMinor === false
            || $amountMinor <= 0
            || $currency === ''
        ) {
            throw new InvalidArgumentException(
                'Paystack refund webhook is missing valid transaction, amount or currency data.'
            );
        }

        $refund = $this->paymentRepository->findRefundForWebhook(
            $providerReference,
            $transactionReference,
            $amountMinor,
            $currency
        );

        if ($refund === null) {
            throw new RuntimeException(
                'No internal refund matches the Paystack refund webhook.'
            );
        }

        if ($status === 'success') {
            throw new RuntimeException(
                'A processed refund requires the financial settlement handler. '
                . 'It must not be marked successful by the status-only handler.'
            );
        }

        return $this->paymentRepository->applyRefundWebhookUpdate(
            (string) $refund['id'],
            $providerReference,
            $status,
            $event
        );
    }
}