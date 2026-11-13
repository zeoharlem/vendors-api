<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Entities;

use DateTimeImmutable;
use DomainException;

final readonly class DisputeEvidence
{
    public function __construct(
        public string $id,
        public string $disputeId,
        public string $uploadedBy,
        public string $originalFilename,
        public string $storageKey,
        public string $mimeType,
        public int $fileSizeBytes,
        public ?string $checksumSha256,
        public DateTimeImmutable $createdAt,
    ) {
        if (trim($this->id) === '') {
            throw new DomainException('Evidence ID is required.');
        }

        if (trim($this->disputeId) === '') {
            throw new DomainException('Dispute ID is required.');
        }

        if (trim($this->uploadedBy) === '') {
            throw new DomainException('Uploader ID is required.');
        }

        if (trim($this->originalFilename) === '') {
            throw new DomainException('Original filename is required.');
        }

        if (trim($this->storageKey) === '') {
            throw new DomainException('Storage key is required.');
        }

        if (trim($this->mimeType) === '') {
            throw new DomainException('MIME type is required.');
        }

        if ($this->fileSizeBytes <= 0) {
            throw new DomainException('Evidence file size must be greater than zero.');
        }

        if (
            $this->checksumSha256 !== null
            && !preg_match('/^[a-f0-9]{64}$/i', $this->checksumSha256)
        ) {
            throw new DomainException('Evidence checksum must be a valid SHA-256 hash.');
        }
    }
}
