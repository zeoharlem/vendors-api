<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Services;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Vendor\Application\Dto\VendorReviewResponseDto;
use App\Modules\Vendor\Application\Exceptions\VendorProfileNotFoundException;
use App\Modules\Vendor\Application\Exceptions\VendorProfileNotPendingException;
use App\Modules\Vendor\Domain\Repository\VendorRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class VendorReviewService
{
    public function __construct(
        private VendorRepository $vendorRepository,
        private NotificationService $notificationService,
    ) {
    }

    /**
     * @return array<int, VendorReviewResponseDto>
     */
    public function listPending(int $limit = 20, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException(
                'Limit must be between 1 and 100.'
            );
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException(
                'Offset cannot be negative.'
            );
        }

        return array_map(
            static fn(array $row): VendorReviewResponseDto =>
                VendorReviewResponseDto::fromArray($row),
            $this->vendorRepository->findPendingForReview($limit, $offset),
        );
    }

    public function getById(string $vendorProfileId): VendorReviewResponseDto
    {
        $profile = $this->vendorRepository->findById($vendorProfileId);

        if ($profile === null) {
            throw new VendorProfileNotFoundException(
                'Vendor profile was not found.'
            );
        }

        return VendorReviewResponseDto::fromArray($profile);
    }

    public function approve(
        string $vendorProfileId,
        string $reviewerId,
    ): VendorReviewResponseDto {
        $profile = $this->vendorRepository->approvePending(
            $vendorProfileId,
            $reviewerId,
        );

        if ($profile === null) {
            $this->throwReviewFailure($vendorProfileId);
        }

        $dto = VendorReviewResponseDto::fromArray($profile);

        $this->notifyVendorSafely(
            userId: $dto->userId,
            vendorProfileId: $dto->id,
            businessName: $dto->businessName,
            status: 'approved',
        );

        return $dto;
    }

    public function reject(
        string $vendorProfileId,
        string $reviewerId,
        string $reason,
    ): VendorReviewResponseDto {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException(
                'Rejection reason must be between 10 and 1000 characters.'
            );
        }

        $profile = $this->vendorRepository->rejectPending(
            $vendorProfileId,
            $reviewerId,
            $reason,
        );

        if ($profile === null) {
            $this->throwReviewFailure($vendorProfileId);
        }

        $dto = VendorReviewResponseDto::fromArray($profile);

        $this->notifyVendorSafely(
            userId: $dto->userId,
            vendorProfileId: $dto->id,
            businessName: $dto->businessName,
            status: 'rejected',
            reason: $reason,
        );

        return $dto;
    }

    private function throwReviewFailure(string $vendorProfileId): never
    {
        // The profile might not exist, or it might no longer be pending.
        $profile = $this->vendorRepository->findById($vendorProfileId);

        if ($profile === null) {
            throw new VendorProfileNotFoundException(
                'Vendor profile was not found.'
            );
        }

        throw new VendorProfileNotPendingException(
            'Only pending vendor profiles can be reviewed.'
        );
    }

    private function notifyVendorSafely(
        string $userId,
        string $vendorProfileId,
        string $businessName,
        string $status,
        ?string $reason = null,
    ): void {
        try {
            if ($status === 'approved') {
                $this->notificationService->notifyVendorApproved(
                    userId: $userId,
                    vendorProfileId: $vendorProfileId,
                    businessName: $businessName,
                );

                return;
            }

            $this->notificationService->notifyVendorRejected(
                userId: $userId,
                vendorProfileId: $vendorProfileId,
                businessName: $businessName,
                reason: (string) $reason,
            );
        } catch (Throwable $exception) {
            error_log(
                sprintf(
                    'Failed to create vendor verification notification for user %s, vendor profile %s, status %s: %s',
                    $userId,
                    $vendorProfileId,
                    $status,
                    $exception->getMessage(),
                )
            );
        }
    }
}