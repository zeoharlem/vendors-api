<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoPaymentRepository implements PaymentRepository
{
    public function __construct(
        private readonly AdapterInterface $db
    ) {
    }

    public function findCustomerOrder(
        string $customerId,
        string $orderId
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                o.id,
                o.order_number,
                o.customer_id,
                o.status,
                o.payment_status,
                o.currency,
                o.total_minor,
                u.email AS customer_email
            FROM public.orders o
            INNER JOIN public.users u
                ON u.id = o.customer_id
            WHERE o.id = :order_id
              AND o.customer_id = :customer_id
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'order_id' => $orderId,
            'customer_id' => $customerId,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function findActivePaymentForOrder(
        string $orderId
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                id,
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status,
                authorization_url,
                access_code,
                provider_transaction_id,
                failure_reason,
                initialized_at,
                verified_at,
                created_at,
                updated_at
            FROM public.payments
            WHERE order_id = :order_id
              AND status IN ('pending', 'initialized')
            ORDER BY created_at DESC
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'order_id' => $orderId,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function createPendingPayment(
        string $orderId,
        string $provider,
        string $reference,
        int $amountMinor,
        string $currency
    ): array {
        $sql = <<<'SQL'
            INSERT INTO public.payments (
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status
            ) VALUES (
                :order_id,
                :provider,
                :reference,
                :amount_minor,
                :currency,
                'pending'
            )
            RETURNING
                id,
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status,
                created_at
        SQL;

        $result = $this->db->query($sql, [
            'order_id' => $orderId,
            'provider' => $provider,
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($currency),
        ]);

        $payment = $result->fetch();

        if ($payment === false) {
            throw new RuntimeException(
                'Unable to create payment record.'
            );
        }

        return $payment;
    }

    public function markPaymentInitialized(
        string $reference,
        string $authorizationUrl,
        ?string $accessCode,
        array $providerResponse
    ): void {
        $sql = <<<'SQL'
            UPDATE public.payments
            SET
                status = 'initialized',
                authorization_url = :authorization_url,
                access_code = :access_code,
                provider_response = CAST(:provider_response AS JSONB),
                initialized_at = NOW(),
                updated_at = NOW()
            WHERE reference = :reference
              AND status = 'pending'
        SQL;

        $this->db->execute($sql, [
            'authorization_url' => $authorizationUrl,
            'access_code' => $accessCode,
            'provider_response' => $this->encodeJson($providerResponse),
            'reference' => $reference,
        ]);

        $this->assertPaymentUpdated($reference, 'initialized');
    }

    public function markPaymentInitializationFailed(
        string $reference,
        string $failureReason,
        array $providerResponse = []
    ): void {
        $sql = <<<'SQL'
            UPDATE public.payments
            SET
                status = 'failed',
                failure_reason = :failure_reason,
                provider_response = CAST(:provider_response AS JSONB),
                failed_at = NOW(),
                updated_at = NOW()
            WHERE reference = :reference
              AND status = 'pending'
        SQL;

        $this->db->execute($sql, [
            'failure_reason' => mb_substr($failureReason, 0, 1000),
            'provider_response' => $this->encodeJson($providerResponse),
            'reference' => $reference,
        ]);

        $this->assertPaymentUpdated($reference, 'failed');
    }

    public function findByReference(string $reference): ?array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                order_id,
                provider,
                reference,
                amount_minor,
                currency,
                status,
                authorization_url,
                access_code,
                provider_transaction_id,
                failure_reason,
                initialized_at,
                verified_at,
                failed_at,
                provider_response,
                created_at,
                updated_at
            FROM public.payments
            WHERE reference = :reference
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'reference' => $reference,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    public function findCustomerPayment(
        string $customerId,
        string $reference
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.order_id,
                p.provider,
                p.reference,
                p.amount_minor,
                p.currency,
                p.status,
                p.authorization_url,
                p.access_code,
                p.provider_transaction_id,
                p.failure_reason,
                p.initialized_at,
                p.verified_at,
                p.failed_at,
                p.created_at,
                p.updated_at
            FROM public.payments p
            INNER JOIN public.orders o
                ON o.id = p.order_id
            WHERE p.reference = :reference
            AND o.customer_id = :customer_id
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'reference' => $reference,
            'customer_id' => $customerId,
        ]);

        $row = $result->fetch();

        return $row !== false ? $row : null;
    }

    private function assertPaymentUpdated(
        string $reference,
        string $expectedStatus
    ): void {
        $payment = $this->findByReference($reference);

        if (
            $payment === null
            || $payment['status'] !== $expectedStatus
        ) {
            throw new RuntimeException(
                sprintf(
                    'Payment %s could not be updated to %s.',
                    $reference,
                    $expectedStatus
                )
            );
        }
    }

    /**
     * @throws JsonException
     */
    private function encodeJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }
}