<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Entities;

use App\Modules\Dispute\Domain\Enums\DisputeResolutionOutcome;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use DateTimeImmutable;
use DomainException;

final class Dispute
{
    public function __construct(
        private string $id,
        private string $orderId,
        private string $vendorOrderId,
        private ?string $vendorPayableId,
        private string $openedBy,
        private string $reason,
        private string $description,
        private DisputeStatus $status = DisputeStatus::OPEN,
        private ?string $resolution = null,
        private ?DisputeResolutionOutcome $resolutionOutcome = null,
        private ?string $resolvedBy = null,
        private ?DateTimeImmutable $resolvedAt = null,
        private ?string $previousVendorOrderStatus = null,
        private ?DateTimeImmutable $closedAt = null,
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = self::required($id, 'Dispute ID');
        $this->orderId = self::required($orderId, 'Order ID');
        $this->vendorOrderId = self::required($vendorOrderId, 'Vendor order ID');
        $this->openedBy = self::required($openedBy, 'Customer ID');
        $this->reason = self::required($reason, 'Dispute reason');
        $this->description = self::required($description, 'Dispute description');

        if (mb_strlen($this->reason) > 100) {
            throw new DomainException('Dispute reason cannot exceed 100 characters.');
        }

        $this->createdAt ??= new DateTimeImmutable();
        $this->updatedAt ??= $this->createdAt;
    }

    public function transitionTo(
        DisputeStatus $newStatus,
        ?DateTimeImmutable $occurredAt = null,
    ): void {
        if (!$this->status->isActive()) {
            throw new DomainException(
                'A dispute that is no longer active cannot change status.'
            );
        }

        $allowed = match ($this->status) {
            DisputeStatus::OPEN => [
                DisputeStatus::UNDER_REVIEW,
            ],

            DisputeStatus::UNDER_REVIEW => [
                DisputeStatus::AWAITING_CUSTOMER,
                DisputeStatus::AWAITING_VENDOR,
                DisputeStatus::RESOLVED_CUSTOMER,
                DisputeStatus::RESOLVED_VENDOR,
                DisputeStatus::REJECTED,
            ],

            DisputeStatus::AWAITING_CUSTOMER,
            DisputeStatus::AWAITING_VENDOR => [
                    DisputeStatus::UNDER_REVIEW,
                    DisputeStatus::RESOLVED_CUSTOMER,
                    DisputeStatus::RESOLVED_VENDOR,
                    DisputeStatus::REJECTED,
                ],

            default => [],
        };

        if (!in_array($newStatus, $allowed, true)) {
            throw new DomainException(sprintf(
                'Dispute cannot transition from "%s" to "%s".',
                $this->status->value,
                $newStatus->value,
            ));
        }

        $this->status = $newStatus;
        $this->touch($occurredAt);
    }


    public function resolve(
        DisputeStatus $resolvedStatus,
        DisputeResolutionOutcome $outcome,
        string $resolvedBy,
        string $resolution,
        ?DateTimeImmutable $resolvedAt = null,
    ): void {
        if (
            !in_array($this->status, [
                DisputeStatus::UNDER_REVIEW,
                DisputeStatus::AWAITING_CUSTOMER,
                DisputeStatus::AWAITING_VENDOR,
            ], true)
        ) {
            throw new DomainException(
                'Only disputes under review or awaiting a response can be resolved.'
            );
        }

        if (
            !in_array($resolvedStatus, [
                DisputeStatus::RESOLVED_CUSTOMER,
                DisputeStatus::RESOLVED_VENDOR,
            ], true)
        ) {
            throw new DomainException('Invalid resolved dispute status.');
        }

        $resolvedBy = self::required($resolvedBy, 'Resolver ID');
        $resolution = self::required($resolution, 'Resolution');

        $this->status = $resolvedStatus;
        $this->resolutionOutcome = $outcome;
        $this->resolvedBy = $resolvedBy;
        $this->resolution = $resolution;
        $this->resolvedAt = $resolvedAt ?? new DateTimeImmutable();

        $this->touch($this->resolvedAt);
    }


    public function close(?DateTimeImmutable $closedAt = null): void
    {
        if (!$this->status->canBeClosed()) {
            throw new DomainException(
                'Only resolved or rejected disputes can be closed.'
            );
        }

        $this->status = DisputeStatus::CLOSED;
        $this->closedAt = $closedAt ?? new DateTimeImmutable();

        $this->touch($this->closedAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function vendorOrderId(): string
    {
        return $this->vendorOrderId;
    }

    public function vendorPayableId(): ?string
    {
        return $this->vendorPayableId;
    }

    public function openedBy(): string
    {
        return $this->openedBy;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function status(): DisputeStatus
    {
        return $this->status;
    }

    public function resolution(): ?string
    {
        return $this->resolution;
    }

    public function resolutionOutcome(): ?DisputeResolutionOutcome
    {
        return $this->resolutionOutcome;
    }

    public function resolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function previousVendorOrderStatus(): ?string
    {
        return $this->previousVendorOrderStatus;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(?DateTimeImmutable $at = null): void
    {
        $this->updatedAt = $at ?? new DateTimeImmutable();
    }

    private static function required(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new DomainException($field . ' is required.');
        }

        return $value;
    }

    public function getStatus(): DisputeStatus
    {
        return $this->status;
    }

}
