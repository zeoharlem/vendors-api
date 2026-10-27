<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application\Dto;

final readonly class VendorReviewResponseDto
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $businessName,
        public ?string $businessRegistrationNumber,
        public string $verificationStatus,
        public ?string $reviewedBy,
        public ?string $reviewedAt,
        public ?string $rejectionReason,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            userId: (string) $row['user_id'],
            businessName: (string) $row['business_name'],
            businessRegistrationNumber: isset($row['business_registration_number'])
            ? (string) $row['business_registration_number']
            : null,
            verificationStatus: (string) $row['verification_status'],
            reviewedBy: isset($row['reviewed_by'])
            ? (string) $row['reviewed_by']
            : null,
            reviewedAt: isset($row['reviewed_at'])
            ? (string) $row['reviewed_at']
            : null,
            rejectionReason: isset($row['rejection_reason'])
            ? (string) $row['rejection_reason']
            : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'business_name' => $this->businessName,
            'business_registration_number' => $this->businessRegistrationNumber,
            'verification_status' => $this->verificationStatus,
            'reviewed_by' => $this->reviewedBy,
            'reviewed_at' => $this->reviewedAt,
            'rejection_reason' => $this->rejectionReason,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}