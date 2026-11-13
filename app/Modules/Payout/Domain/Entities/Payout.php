<?php

declare(strict_types=1);

namespace App\Modules\Payout\Domain\Entities;

use App\Modules\Payout\Domain\Enums\PayoutStatus;
use DomainException;
use DateTimeImmutable;

final class Payout
{
    public function __construct(
        private readonly string $id,
        private readonly string $vendorPayableId,
        private readonly string $storeId,
        private readonly ?string $payoutAccountId,
        private readonly string $provider,
        private readonly string $idempotencyKey,
        private readonly ?string $providerReference,
        private readonly ?string $providerTransferId,
        private readonly int $amountMinor,
        private readonly string $currency,
        private PayoutStatus $status,
        private readonly ?string $failureReason,
        private readonly DateTimeImmutable $requestedAt,
        private readonly ?DateTimeImmutable $processedAt,
        private readonly ?DateTimeImmutable $confirmedAt,
        private readonly ?array $providerResponse,
        private readonly ?int $grossAmountMinor,
        private readonly int $debtRecoveredMinor,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
    ) {
        if ($amountMinor < 0) {
            throw new DomainException('Payout amount cannot be negative.');
        }

        if ($debtRecoveredMinor < 0) {
            throw new DomainException('Recovered debt cannot be negative.');
        }

        if ($grossAmountMinor !== null && $grossAmountMinor < $amountMinor) {
            throw new DomainException(
                'Gross payout amount cannot be less than the transfer amount.'
            );
        }

        if (
            $grossAmountMinor !== null
            && $debtRecoveredMinor > $grossAmountMinor
        ) {
            throw new DomainException(
                'Recovered debt cannot exceed the gross payout amount.'
            );
        }

        if ($amountMinor === 0 && $debtRecoveredMinor === 0) {
            throw new DomainException(
                'A zero-value payout must recover a positive amount of vendor debt.'
            );
        }

        if (
            $amountMinor > 0
            && $grossAmountMinor !== null
            && ($amountMinor + $debtRecoveredMinor) !== $grossAmountMinor
        ) {
            throw new DomainException(
                'Transfer amount and recovered debt must equal the gross payout amount.'
            );
        }

        if (
            $amountMinor === 0
            && ($debtRecoveredMinor === 0 || $provider !== 'internal')
        ) {
            throw new DomainException(
                'A zero-value payout must be an internal settlement recovering vendor debt.'
            );
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function vendorPayableId(): string
    {
        return $this->vendorPayableId;
    }

    public function storeId(): string
    {
        return $this->storeId;
    }

    public function payoutAccountId(): ?string
    {
        return $this->payoutAccountId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function providerTransferId(): ?string
    {
        return $this->providerTransferId;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function status(): PayoutStatus
    {
        return $this->status;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function processedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function providerResponse(): ?array
    {
        return $this->providerResponse;
    }

    public function grossAmountMinor(): ?int
    {
        return $this->grossAmountMinor;
    }

    public function debtRecoveredMinor(): int
    {
        return $this->debtRecoveredMinor;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markProcessing(): void
    {
        $this->transitionTo(PayoutStatus::PROCESSING);
    }

    public function markSuccessful(): void
    {
        $this->transitionTo(PayoutStatus::SUCCESS);
    }

    public function markFailed(): void
    {
        $this->transitionTo(PayoutStatus::FAILED);
    }

    public function markReversed(): void
    {
        $this->transitionTo(PayoutStatus::REVERSED);
    }

    public function markCancelled(): void
    {
        $this->transitionTo(PayoutStatus::CANCELLED);
    }

    public function isInternalDebtSettlement(): bool
    {
        return $this->amountMinor === 0
            && $this->debtRecoveredMinor > 0
            && $this->provider === 'internal';
    }

    private function transitionTo(PayoutStatus $next): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new DomainException(sprintf(
                'Payout cannot transition from "%s" to "%s".',
                $this->status->value,
                $next->value
            ));
        }

        $this->status = $next;
    }
}