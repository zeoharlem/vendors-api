<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Application\Services;

use App\Modules\Dispute\Application\Dtos\UploadDisputeEvidenceDto;
use App\Modules\Dispute\Domain\Contracts\DisputeEvidenceStorageInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeTransactionManagerInterface;
use App\Modules\Dispute\Domain\Entities\DisputeEvidence;
use App\Modules\Dispute\Domain\Entities\DisputeHistory;
use App\Modules\Dispute\Domain\Enums\DisputeEventType;
use App\Modules\Dispute\Domain\Repositories\DisputeEvidenceRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeHistoryRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeRepositoryInterface;
use App\Modules\Dispute\Domain\Enums\DisputeStatus;
use InvalidArgumentException;
use DomainException;
use RuntimeException;
use Throwable;

final readonly class DisputeEvidenceService
{
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private DisputeRepositoryInterface $disputeRepository,
        private DisputeEvidenceRepositoryInterface $evidenceRepository,
        private DisputeHistoryRepositoryInterface $historyRepository,
        private DisputeEvidenceStorageInterface $storage,
        private DisputeTransactionManagerInterface $transactionManager,
    ) {
    }

    public function uploadForCustomer(
        UploadDisputeEvidenceDto $dto,
        string $customerId
    ): DisputeEvidence {
        $dispute = $this->disputeRepository->findByIdForCustomer(
            $dto->disputeId,
            $customerId
        );

        if ($dispute === null) {
            throw new DomainException(
                'Dispute was not found or you do not have access to it.'
            );
        }

        return $this->upload(
            $dto,
            $customerId,
            $dispute
        );
    }

    public function uploadForStore(
        UploadDisputeEvidenceDto $dto,
        string $storeId,
        string $actorId
    ): DisputeEvidence {
        $dispute = $this->disputeRepository->findByIdForStore(
            $dto->disputeId,
            $storeId
        );

        if ($dispute === null) {
            throw new DomainException(
                'Dispute was not found or you do not have access to it.'
            );
        }

        return $this->upload(
            $dto,
            $actorId,
            $dispute
        );
    }

    private function upload(
        UploadDisputeEvidenceDto $dto,
        string $actorId,
        \App\Modules\Dispute\Domain\Entities\Dispute $dispute
    ): DisputeEvidence {
        if (!$dispute->status()->isActive()) {
            throw new DomainException(
                'Evidence cannot be uploaded to a closed or resolved dispute.'
            );
        }

        $file = $this->inspectUploadedFile($dto->uploadedFile);

        $evidenceId = $this->generateUuid();

        $storageKey = sprintf(
            '%s/%s.%s',
            $dto->disputeId,
            bin2hex(random_bytes(16)),
            $file['extension']
        );

        $this->storage->store(
            $file['temporary_path'],
            $storageKey,
            $file['mime_type']
        );

        try {
            return $this->transactionManager->transactional(
                function () use ($dto, $actorId, $storageKey, $evidenceId, $file): DisputeEvidence {
                    $lockedDispute = $this->disputeRepository->findByIdForUpdate(
                        $dto->disputeId
                    );

                    if (
                        $lockedDispute === null
                        || !$lockedDispute->status()->isActive()
                    ) {
                        throw new DomainException(
                            'The dispute is no longer accepting evidence.'
                        );
                    }

                    $evidence = new DisputeEvidence(
                        id: $evidenceId,
                        disputeId: $dto->disputeId,
                        uploadedBy: $actorId,
                        originalFilename: $file['original_filename'],
                        storageKey: $storageKey,
                        mimeType: $file['mime_type'],
                        fileSizeBytes: $file['file_size'],
                        checksumSha256: $file['checksum'],
                        createdAt: new \DateTimeImmutable()
                    );

                    $savedEvidence = $this->evidenceRepository->create(
                        $evidence
                    );

                    $history = new DisputeHistory(
                        id: $this->generateUuid(),
                        disputeId: $dto->disputeId,
                        actorId: $actorId,
                        eventType: DisputeEventType::EVIDENCE_UPLOADED,
                        previousStatus: $lockedDispute->status(),
                        newStatus: $lockedDispute->status(),
                        note: 'Dispute evidence uploaded.',
                        metadata: [
                            'evidence_id' => $evidenceId,
                            'original_filename' => $file['original_filename'],
                            'mime_type' => $file['mime_type'],
                            'file_size_bytes' => $file['file_size'],
                            'checksum_sha256' => $file['checksum'],
                        ],
                        createdAt: new \DateTimeImmutable()
                    );

                    $this->historyRepository->append($history);

                    return $savedEvidence;
                }
            );
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($storageKey);
            } catch (Throwable) {
                // Preserve the original database/application exception.
                // Failed cleanup should be logged by the infrastructure layer.
            }

            throw $exception;
        }
    }

    /**
     * @return array{
     *     temporary_path: string,
     *     original_filename: string,
     *     mime_type: string,
     *     extension: string,
     *     file_size: int,
     *     checksum: string
     * }
     */
    private function inspectUploadedFile(object $file): array
    {
        foreach ([
            'getTempName',
            'getName',
            'getSize',
            'isUploadedFile',
        ] as $method) {
            if (!method_exists($file, $method)) {
                throw new InvalidArgumentException(
                    'Invalid uploaded evidence file object.'
                );
            }
        }

        if (!$file->isUploadedFile()) {
            throw new DomainException(
                'The supplied file is not a valid HTTP upload.'
            );
        }

        if (
            method_exists($file, 'getError')
            && $file->getError() !== UPLOAD_ERR_OK
        ) {
            throw new DomainException(
                'The evidence upload did not complete successfully.'
            );
        }

        $temporaryPath = $file->getTempName();

        if (
            !is_string($temporaryPath)
            || $temporaryPath === ''
            || !is_file($temporaryPath)
            || !is_readable($temporaryPath)
        ) {
            throw new DomainException(
                'The uploaded evidence file is unavailable.'
            );
        }

        $fileSize = filesize($temporaryPath);

        if ($fileSize === false || $fileSize <= 0) {
            throw new DomainException(
                'The evidence file is empty or its size could not be determined.'
            );
        }

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new DomainException(
                'Evidence files must not exceed 10 MB.'
            );
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($temporaryPath);

        if (
            !is_string($mimeType)
            || !isset(self::ALLOWED_MIME_TYPES[$mimeType])
        ) {
            throw new DomainException(
                'Unsupported evidence file type. Upload PDF, JPEG, PNG or WebP.'
            );
        }

        $originalFilename = $this->sanitizeFilename(
            (string) $file->getName()
        );

        $checksum = hash_file('sha256', $temporaryPath);

        if ($checksum === false) {
            throw new RuntimeException(
                'Unable to calculate evidence file checksum.'
            );
        }

        return [
            'temporary_path' => $temporaryPath,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'extension' => self::ALLOWED_MIME_TYPES[$mimeType],
            'file_size' => $fileSize,
            'checksum' => $checksum,
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));

        $filename = preg_replace(
            '/[\x00-\x1F\x7F]/',
            '',
            $filename
        ) ?? '';

        $filename = trim($filename);

        if ($filename === '') {
            $filename = 'evidence';
        }

        if (mb_strlen($filename) > 255) {
            $filename = mb_substr($filename, 0, 255);
        }

        return $filename;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
