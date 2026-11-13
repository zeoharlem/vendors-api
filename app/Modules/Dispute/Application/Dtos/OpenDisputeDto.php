<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Dtos;

use InvalidArgumentException;
use Phalcon\Http\RequestInterface;

final readonly class OpenDisputeDto
{
    public function __construct(
        public string $vendorOrderId,
        public string $reason,
        public string $description,
    ) {
        if (trim($this->vendorOrderId) === '') {
            throw new InvalidArgumentException('Vendor order ID is required.');
        }

        if (trim($this->reason) === '') {
            throw new InvalidArgumentException('Dispute reason is required.');
        }

        if (mb_strlen(trim($this->reason)) > 100) {
            throw new InvalidArgumentException(
                'Dispute reason cannot exceed 100 characters.'
            );
        }

        if (trim($this->description) === '') {
            throw new InvalidArgumentException('Dispute description is required.');
        }
    }

    public static function fromRequest(RequestInterface $request): self
    {
        $data = $request->getJsonRawBody(true);

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON request body.');
        }

        return new self(
            vendorOrderId: trim((string) ($data['vendor_order_id'] ?? '')),
            reason: trim((string) ($data['reason'] ?? '')),
            description: trim((string) ($data['description'] ?? '')),
        );
    }

    public function toArray(): array
    {
        return [
            'vendor_order_id' => $this->vendorOrderId,
            'reason' => $this->reason,
            'description' => $this->description,
        ];
    }
}
