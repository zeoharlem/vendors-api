<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Dtos;

use InvalidArgumentException;
use Phalcon\Http\RequestInterface;

final readonly class RespondToDisputeDto
{
    public function __construct(
        public string $message,
    ) {
        if (trim($this->message) === '') {
            throw new InvalidArgumentException('Response message is required.');
        }
    }

    public static function fromRequest(RequestInterface $request): self
    {
        $data = $request->getJsonRawBody(true);

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON request body.');
        }

        return new self(
            message: trim((string) ($data['message'] ?? '')),
        );
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message,
        ];
    }
}
