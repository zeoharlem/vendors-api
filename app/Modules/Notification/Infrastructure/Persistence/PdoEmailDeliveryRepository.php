<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Persistence;

use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoEmailDeliveryRepository implements EmailDeliveryRepositoryInterface
{
    private const PROCESSING_LEASE_HOURS = 2;

    public function __construct(
        private readonly AdapterInterface $db,
    ) {
    }

    public function createPending(
        string $idempotencyKey,
        ?string $outboxEventId,
        string $recipientEmail,
        ?string $recipientName,
        ?string $templateKey,
        string $subject,
        array $templateData = [],
    ): array {
        $templateDataJson = json_encode(
            $templateData,
            JSON_THROW_ON_ERROR
        );

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.email_deliveries (
                    idempotency_key,
                    outbox_event_id,
                    recipient_email,
                    recipient_name,
                    template_key,
                    template_data,
                    subject,
                    status,
                    created_at,
                    updated_at
                )
                VALUES (
                    :idempotency_key,
                    :outbox_event_id,
                    :recipient_email,
                    :recipient_name,
                    :template_key,
                    CAST(:template_data AS JSONB),
                    :subject,
                    'pending',
                    NOW(),
                    NOW()
                )
                ON CONFLICT (idempotency_key) DO NOTHING
                RETURNING *
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
                'outbox_event_id' => $outboxEventId,
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'template_key' => $templateKey,
                'template_data' => $templateDataJson,
                'subject' => $subject,
            ]
        );

        $delivery = $result->fetch();

        if ($delivery !== false) {
            return $delivery;
        }

        $existing = $this->findByIdempotencyKey($idempotencyKey);

        if ($existing === null) {
            throw new RuntimeException(
                'Unable to retrieve the existing email delivery.'
            );
        }

        $existingTemplateData = $existing['template_data'] ?? [];

        if (is_string($existingTemplateData)) {
            $existingTemplateData = json_decode(
                $existingTemplateData,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        if (!is_array($existingTemplateData)) {
            throw new RuntimeException(
                'Existing email delivery contains invalid template data.',
            );
        }

        $sameDeliveryData = (string) ($existing['outbox_event_id'] ?? '') === (string) ($outboxEventId ?? '')
            && strcasecmp((string) $existing['recipient_email'],$recipientEmail,) === 0
            && ($existing['recipient_name'] ?? null) === $recipientName
            && (string) $existing['subject'] === $subject
            && ($existing['template_key'] ?? null) === $templateKey
            && $this->normalizeJsonValue($existingTemplateData)
            === $this->normalizeJsonValue($templateData);

        if (!$sameDeliveryData) {
            throw new RuntimeException(
                'Email idempotency key was reused with different delivery data.',
            );
        }

        return $existing;
    }

    /**
     * Normalizes JSON data for reliable structural comparison.
     *
     * Object keys are sorted recursively, while list ordering is preserved.
     */
    private function normalizeJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeJsonValue($item),$value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeJsonValue($item);
        }

        return $value;
    }

    public function findById(string $deliveryId): ?array
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.email_deliveries
                WHERE id = :id
                LIMIT 1
            SQL,
            [
                'id' => $deliveryId,
            ]
        );

        $delivery = $result->fetch();

        return $delivery === false ? null : $delivery;
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $result = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.email_deliveries
                WHERE idempotency_key = :idempotency_key
                LIMIT 1
            SQL,
            [
                'idempotency_key' => $idempotencyKey,
            ]
        );

        $delivery = $result->fetch();

        return $delivery === false ? null : $delivery;
    }

    public function claimForProcessing(string $deliveryId): bool
    {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.email_deliveries
                SET
                    status = 'processing',
                    attempts = attempts + 1,
                    processing_at = NOW(),
                    last_error = NULL,
                    updated_at = NOW()
                WHERE id = :id
                AND (
                        status IN ('pending', 'failed')
                        OR (
                            status = 'processing'
                            AND processing_at <=
                                NOW() - INTERVAL '2 hours'
                        )
                )
                RETURNING id
            SQL,
            [
                'id' => $deliveryId,
            ]
        );

        return $result->fetch() !== false;
    }

    public function markSent(
        string $deliveryId,
        ?string $providerMessageId,
    ): bool {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.email_deliveries
                SET
                    status = 'sent',
                    provider_message_id = COALESCE(
                        :provider_message_id,
                        provider_message_id
                    ),
                    sent_at = COALESCE(sent_at, NOW()),
                    processing_at = NULL,
                    last_error = NULL,
                    updated_at = NOW()
                WHERE id = :id
                AND status IN ('processing', 'sent')
                RETURNING id
            SQL,
            [
                'id' => $deliveryId,
                'provider_message_id' => $providerMessageId,
            ]
        );

        return $result->fetch() !== false;
    }

    public function markFailed(
        string $deliveryId,
        string $error,
    ): bool {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.email_deliveries
                SET
                    status = 'failed',
                    last_error = :last_error,
                    processing_at = NULL,
                    updated_at = NOW()
                WHERE id = :id
                AND status = 'processing'
                RETURNING id
            SQL,
            [
                'id' => $deliveryId,
                'last_error' => mb_substr($error, 0, 10000),
            ]
        );

        return $result->fetch() !== false;
    }
}