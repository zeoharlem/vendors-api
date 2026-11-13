<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Payments;

use App\Modules\Dispute\Domain\Contracts\DisputeRefundManagerInterface;
use App\Modules\Payment\Application\Services\PaymentService;
use RuntimeException;

final readonly class PaymentServiceDisputeRefundManager implements DisputeRefundManagerInterface
{
    public function __construct(
        private PaymentService $paymentService,
    ) {
    }

    public function requestRefund(
        string $disputeId,
        string $vendorOrderId,
        string $requestedBy,
        ?int $amountMinor,
        string $reason,
        string $idempotencyKey,
    ): string {
        $disputeId = trim($disputeId);

        if ($disputeId === '') {
            throw new RuntimeException('Dispute ID is required.');
        }

        $refund = $this->paymentService->refundVendorOrder(
            vendorOrderId: $vendorOrderId,
            amountMinor: $amountMinor,
            requestedBy: $requestedBy,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
        );

        $refundId = $refund['id'] ?? null;

        if (!is_string($refundId) || trim($refundId) === '') {
            throw new RuntimeException(
                'Payment service did not return a valid refund ID.'
            );
        }

        return $refundId;
    }
}