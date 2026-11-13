<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Dtos;

use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use InvalidArgumentException;
use Phalcon\Http\RequestInterface;

final readonly class UpdateDisputeStatusDto
{
    public function __construct(
        public DisputeStatus $status,
        public ?string $note = null,
    ) {
        if (
            !in_array($status, [
                DisputeStatus::UNDER_REVIEW,
                DisputeStatus::AWAITING_CUSTOMER,
                DisputeStatus::AWAITING_VENDOR,
            ], true)
        ) {
            throw new InvalidArgumentException(
                'The requested status is not permitted through this operation.'
            );
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw new InvalidArgumentException(
                'Status note cannot exceed 2000 characters.'
            );
        }
    }

    public static function fromRequest(RequestInterface $request): self
    {
        $data = $request->getJsonRawBody(true);

        if (!is_array($data)) {
            throw new InvalidArgumentException('A valid JSON request body is required.');
        }

        $statusValue = $data['status'] ?? null;

        if (!is_string($statusValue)) {
            throw new InvalidArgumentException('A valid dispute status is required.');
        }

        $status = DisputeStatus::tryFrom($statusValue);

        if ($status === null) {
            throw new InvalidArgumentException('The supplied dispute status is invalid.');
        }

        $note = $data['note'] ?? null;

        if ($note !== null && !is_string($note)) {
            throw new InvalidArgumentException('The status note must be a string.');
        }

        return new self(
            status: $status,
            note: $note !== null ? trim($note) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'note' => $this->note,
        ];
    }
}
