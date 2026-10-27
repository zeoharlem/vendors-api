<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Infrastructure\Persistence;

use App\Modules\Vendor\Application\Exceptions\VendorProfileAlreadyExistsException;
use App\Modules\Vendor\Domain\Repository\VendorRepository;
use Phalcon\Contracts\Db\Adapter\Adapter;
use Phalcon\Db\Enum;
use Throwable;

final class PdoVendorRepository implements VendorRepository
{
    public function __construct(private readonly Adapter $db, )
    {
    }

    public function findByUserId(string $userId): ?array
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT
                    id,
                    user_id,
                    business_name,
                    business_registration_number,
                    verification_status,
                    created_at,
                    updated_at
                FROM public.vendor_profiles
                WHERE user_id = :userId
                LIMIT 1
                SQL,
            Enum::FETCH_ASSOC,
            ['userId' => $userId],
        );

        return $row === false ? null : $row;
    }

    public function updateForUser(
        string $userId,
        string $businessName,
        ?string $businessRegistrationNumber,
    ): ?array {
        $row = $this->db->fetchOne(
            <<<'SQL'
                UPDATE public.vendor_profiles
                SET
                    business_name = :businessName,
                    business_registration_number = :businessRegistrationNumber,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = :userId
                RETURNING
                    id,
                    user_id,
                    business_name,
                    business_registration_number,
                    verification_status,
                    created_at,
                    updated_at
                SQL,
            Enum::FETCH_ASSOC,
            [
                'userId' => $userId,
                'businessName' => $businessName,
                'businessRegistrationNumber' => $businessRegistrationNumber,
            ],
        );

        return $row === false ? null : $row;
    }

    public function createForUser(
        string $userId,
        string $businessName,
        ?string $businessRegistrationNumber,
    ): array {
        $this->db->begin();

        try {
            $profile = $this->db->fetchOne(
                <<<'SQL'
                    INSERT INTO public.vendor_profiles (
                        user_id,
                        business_name,
                        business_registration_number
                    )
                    VALUES (
                        :userId,
                        :businessName,
                        :businessRegistrationNumber
                    )
                    RETURNING
                        id,
                        user_id,
                        business_name,
                        business_registration_number,
                        verification_status,
                        created_at,
                        updated_at
                    SQL,
                Enum::FETCH_ASSOC,
                [
                    'userId' => $userId,
                    'businessName' => $businessName,
                    'businessRegistrationNumber' => $businessRegistrationNumber,
                ],
            );

            if ($profile === false) {
                throw new \RuntimeException('The vendor profile could not be created.');
            }

            // Role ID 2 is the existing "vendor" role.
            $this->db->execute(
                <<<'SQL'
                    INSERT INTO public.user_roles (user_id, role_id)
                    VALUES (:userId, 2)
                    ON CONFLICT (user_id, role_id) DO NOTHING
                    SQL,
                ['userId' => $userId],
            );

            $this->db->commit();

            return $profile;
        } catch (Throwable $exception) {
            $this->db->rollback();

            // Keep the database unique constraint as the final safeguard
            // against two concurrent profile-creation requests.
            if (
                (string) $exception->getCode() === '23505'
                && str_contains($exception->getMessage(), 'vendor_profiles_user_id_key')
            ) {
                throw new VendorProfileAlreadyExistsException();
            }

            throw $exception;
        }
    }

    public function findPendingForReview(int $limit, int $offset): array
    {
        $sql = <<<'SQL'
        SELECT
            id,
            user_id,
            business_name,
            business_registration_number,
            verification_status,
            reviewed_by,
            reviewed_at,
            rejection_reason,
            created_at,
            updated_at
        FROM public.vendor_profiles
        WHERE verification_status = :status
        ORDER BY created_at ASC, id ASC
        LIMIT :limit OFFSET :offset
    SQL;

        return $this->db->fetchAll(
            $sql,
            Enum::FETCH_ASSOC,
            [
                'status' => 'pending',
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    public function findById(string $vendorProfileId): ?array
    {
        $sql = <<<'SQL'
        SELECT
            id,
            user_id,
            business_name,
            business_registration_number,
            verification_status,
            reviewed_by,
            reviewed_at,
            rejection_reason,
            created_at,
            updated_at
        FROM public.vendor_profiles
        WHERE id = :id
        LIMIT 1
    SQL;

        $row = $this->db->fetchOne(
            $sql,
            Enum::FETCH_ASSOC,
            ['id' => $vendorProfileId]
        );

        return $row ?: null;
    }

    public function approvePending(
        string $vendorProfileId,
        string $reviewerId
    ): ?array {
        $sql = <<<'SQL'
        UPDATE public.vendor_profiles
        SET
            verification_status = :status,
            reviewed_by = :reviewer_id,
            reviewed_at = NOW(),
            rejection_reason = NULL,
            updated_at = NOW()
        WHERE id = :id
          AND verification_status = :pending_status
        RETURNING
            id,
            user_id,
            business_name,
            business_registration_number,
            verification_status,
            reviewed_by,
            reviewed_at,
            rejection_reason,
            created_at,
            updated_at
    SQL;

        $row = $this->db->fetchOne(
            $sql,
            Enum::FETCH_ASSOC,
            [
                'status' => 'verified',
                'reviewer_id' => $reviewerId,
                'id' => $vendorProfileId,
                'pending_status' => 'pending',
            ]
        );

        return $row ?: null;
    }

    public function rejectPending(
        string $vendorProfileId,
        string $reviewerId,
        string $reason
    ): ?array {
        $sql = <<<'SQL'
        UPDATE public.vendor_profiles
        SET
            verification_status = :status,
            reviewed_by = :reviewer_id,
            reviewed_at = NOW(),
            rejection_reason = :reason,
            updated_at = NOW()
        WHERE id = :id
          AND verification_status = :pending_status
        RETURNING
            id,
            user_id,
            business_name,
            business_registration_number,
            verification_status,
            reviewed_by,
            reviewed_at,
            rejection_reason,
            created_at,
            updated_at
    SQL;

        $row = $this->db->fetchOne(
            $sql,
            Enum::FETCH_ASSOC,
            [
                'status' => 'rejected',
                'reviewer_id' => $reviewerId,
                'reason' => $reason,
                'id' => $vendorProfileId,
                'pending_status' => 'pending',
            ]
        );

        return $row ?: null;
    }

    public function findUserIdByProfileId(string $vendorProfileId): ?string
    {
        $sql = <<<'SQL'
        SELECT user_id
        FROM public.vendor_profiles
        WHERE id = :id
        LIMIT 1
    SQL;

        $row = $this->db->fetchOne(
            $sql,
            Enum::FETCH_ASSOC,
            ['id' => $vendorProfileId]
        );

        return isset($row['user_id']) ? (string) $row['user_id'] : null;
    }
}