<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers;

use JsonException;
use RuntimeException;
use Throwable;

final class PaymentWebhookController extends \Phalcon\Mvc\Controller
{
    public function paystackAction(): mixed
    {
        $eventId = null;

        try {
            // 1. Read the exact raw request body.
            $rawBody = $this->request->getRawBody();

            if (!is_string($rawBody) || $rawBody === '') {
                return $this->jsonResponder->error(
                    'BAD_REQUEST',
                    'Webhook request body is required.',
                    400
                );
            }

            // 2. Verify the Paystack signature before decoding JSON.
            $signature = trim(
                (string) $this->request->getHeader('x-paystack-signature')
            );

            $signatureVerifier = $this->di->getShared(
                'paystackWebhookSignatureVerifier'
            );

            if (!$signatureVerifier->isValid($rawBody, $signature)) {
                return $this->jsonResponder->error(
                    'UNAUTHORIZED',
                    'Invalid webhook signature.',
                    401
                );
            }

            // 3. Decode the verified payload.
            try {
                $payload = json_decode(
                    $rawBody,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                return $this->jsonResponder->error(
                    'BAD_REQUEST',
                    'Invalid webhook JSON payload.',
                    400
                );
            }

            if (
                !is_array($payload)
                || !isset($payload['event'])
                || !is_string($payload['event'])
                || !isset($payload['data'])
                || !is_array($payload['data'])
            ) {
                return $this->jsonResponder->error(
                    'BAD_REQUEST',
                    'Invalid webhook event structure.',
                    400
                );
            }

            $eventType = $payload['event'];
            $data = $payload['data'];

            // 4. Generate a stable event identifier.
            $transactionId = $data['id'] ?? null;

            $eventId = is_scalar($transactionId)
                ? $eventType . ':' . (string) $transactionId
                : $eventType . ':' . hash('sha256', $rawBody);

            $reference = trim((string) ($data['reference'] ?? ''));

            $paymentRepository = $this->di->getShared('paymentRepository');
            $paymentEventRepository = $this->di->getShared('paymentEventRepository');

            // 5. Find the payment, if it already exists.
            $payment = $reference !== '' ? $paymentRepository->findByReference($reference) : null;

            // 6. Persist the event. Duplicate inserts return null.
            $event = $paymentEventRepository->recordReceivedEvent(
                provider: 'paystack',
                providerEventId: $eventId,
                eventType: $eventType,
                payload: $payload,
                paymentId: $payment['id'] ?? null
            );

            if ($event === null) {
                // The event already exists. Check whether it was processed.
                $event = $paymentEventRepository->findByProviderEventId(
                    provider: 'paystack',
                    providerEventId: $eventId
                );
            }

            if ($event === null) {
                throw new RuntimeException(
                    'Unable to persist or retrieve Paystack webhook event.'
                );
            }

            // 7. A successfully processed duplicate needs no further work.
            if ($event['status'] === 'processed') {
                return $this->jsonResponder->success(
                    [
                        'received' => true,
                        'duplicate' => true,
                    ]
                );
            }

            // 8. Ignore other event types safely.
            if ($eventType !== 'charge.success') {
                $paymentEventRepository->markProcessed(
                    (string) $event['id']
                );

                return $this->jsonResponder->success([
                    'received' => true,
                ]);
            }

            if ($reference === '') {
                throw new RuntimeException(
                    'Paystack charge.success event has no payment reference.'
                );
            }

            // 9. Independently verify the transaction with Paystack.
            $paymentService = $this->di->getShared('paymentService');

            $verifiedPayment = $paymentService->verify($reference);

            if (($verifiedPayment['status'] ?? null) !== 'success') {
                throw new RuntimeException(
                    'Paystack transaction has not been confirmed as successful.'
                );
            }

            // 10. Mark the webhook event as processed.
            $paymentEventRepository->markProcessed(
                (string) $event['id']
            );

            return $this->jsonResponder->success(
                [
                    'received' => true,
                    'processed' => true,
                ]
            );

        } catch (Throwable $exception) {
            error_log(
                'Paystack webhook processing failed: '
                . $exception->getMessage()
            );

            if ($eventId !== null) {
                try {
                    $paymentEventRepository = $this->di->getShared(
                        'paymentEventRepository'
                    );

                    $event = $paymentEventRepository->findByProviderEventId(
                        provider: 'paystack',
                        providerEventId: $eventId
                    );

                    if ($event !== null && $event['status'] !== 'processed') {
                        $paymentEventRepository->markFailed(
                            (string) $event['id'],
                            $exception->getMessage()
                        );
                    }
                } catch (Throwable $loggingException) {
                    error_log(
                        'Unable to update failed Paystack event: '
                        . $loggingException->getMessage()
                    );
                }
            }

            return $this->jsonResponder->error(
                'WEBHOOK_PROCESSING_FAILED',
                'Unable to process payment notification.',
                500
            );
        }
    }
}