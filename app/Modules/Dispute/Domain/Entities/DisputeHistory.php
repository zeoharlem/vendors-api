<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Entities;

use App\Modules\Dispute\Domain\Enums\DisputeEventType;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use DateTimeImmutable;
use DomainException;

final readonly class DisputeHistory
{
    public function __construct(
        public string $id,
        public string $disputeId,
        public ?string $actorId,
        public DisputeEventType $eventType,
        public ?DisputeStatus $previousStatus,
        public ?DisputeStatus $newStatus,
        public ?string $note,
        public array $metadata,
        public DateTimeImmutable $createdAt,
    ) {
        if (trim($this->id) === '') {
            throw new DomainException('History ID is required.');
        }

        if (trim($this->disputeId) === '') {
            throw new DomainException('Dispute ID is required.');
        }

        if ($this->actorId !== null && trim($this->actorId) === '') {
            throw new DomainException('Actor ID cannot be empty.');
        }
    }
}
