<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Dtos;

use InvalidArgumentException;
use Phalcon\Http\RequestInterface;

final readonly class UploadDisputeEvidenceDto
{
    public function __construct(
        public string $disputeId,
        public object $uploadedFile,
    ) {
        if ($this->disputeId === '') {
            throw new InvalidArgumentException('Dispute ID is required.');
        }

        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $this->disputeId
            )
        ) {
            throw new InvalidArgumentException('A valid dispute ID is required.');
        }
    }

    public static function fromRequest(RequestInterface $request): self
    {
        $disputeId = $request->getPost('dispute_id');

        if (!is_string($disputeId)) {
            throw new InvalidArgumentException('Dispute ID is required.');
        }

        $files = $request->getUploadedFiles(true, true);
        $uploadedFile = $files['file'] ?? null;

        // A named upload may be returned as an array when multiple files
        // use the same field name. This endpoint accepts exactly one file.
        if (is_array($uploadedFile)) {
            throw new InvalidArgumentException('Upload exactly one evidence file.');
        }

        if (!is_object($uploadedFile)) {
            throw new InvalidArgumentException('An evidence file is required.');
        }

        return new self(
            disputeId: trim($disputeId),
            uploadedFile: $uploadedFile,
        );
    }
}
