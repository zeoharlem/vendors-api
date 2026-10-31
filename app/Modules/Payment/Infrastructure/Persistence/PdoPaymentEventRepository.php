<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Repositories\PaymentEventRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoPaymentEventRepository implements PaymentEventRepository
{
    public function __construct(
        private readonly AdapterInterface $db
    ) {
    }

    public function recordReceivedEvent(
        string $provider,
        string $providerEventId,
        string $eventType,
        array $payload,
        ?string $paymentId = null
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.payment_events (
                    provider,
                    provider_event_id,
                    event_type,
                    payment_id,
                    payload,
                    status,
                    attempts,
                    received_at
                )
                VALUES (
                    :provider,
                    :provider_event_id,
                    :event_type,
                    :payment_id,
                    CAST(:payload AS JSONB),
                    'received',
                    0,
                    NOW()
                )
                ON CONFLICT (provider, provider_event_id)
                DO NOTHING
                RETURNING
                    id,
                    provider,
                    provider_event_id,
                    event_type,
                    payment_id,
                    status,
                    attempts,
                    received_at
            SQL,
            [
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'payment_id' => $paymentId,
                'payload' => $this->encodeJson($payload),
            ]
        );

        $event = $result->fetch();

        return $event ?: null;
    }

    public function markProcessed(string $eventId): void
    {
        $this->db->execute(
            <<<'SQL'
                UPDATE public.payment_events
                SET
                    status = 'processed',
                    processed_at = NOW(),
                    last_error = NULL
                WHERE id = :id
            SQL,
            ['id' => $eventId]
        );
    }

    public function markFailed(
        string $eventId,
        string $error
    ): void {
        $this->db->execute(
            <<<'SQL'
                UPDATE public.payment_events
                SET
                    status = 'failed',
                    attempts = attempts + 1,
                    last_error = :last_error
                WHERE id = :id
            SQL,
            [
                'id' => $eventId,
                'last_error' => mb_substr($error, 0, 5000),
            ]
        );
    }

    private function encodeJson(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'Unable to encode payment event payload.',
                0,
                $exception
            );
        }
    }

    public function findByProviderEventId(
        string $provider,
        string $providerEventId
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                id,
                provider,
                provider_event_id,
                event_type,
                payment_id,
                status,
                attempts,
                received_at,
                processed_at
            FROM public.payment_events
            WHERE provider = :provider
              AND provider_event_id = :provider_event_id
            LIMIT 1
        SQL,
            [
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
            ]
        );

        $event = $result->fetch();

        return $event ?: null;
    }
}