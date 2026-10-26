<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Persistence;

use App\Modules\Store\Domain\Repository\StoreRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Phalcon\Db\Enum;

final class PdoStoreRepository implements StoreRepository
{
    public function __construct(
        private readonly AdapterInterface $db,
    ) {}

    public function createForVendor(
        string $vendorProfileId,
        string $name,
        string $slug,
        ?string $description,
    ): array {
        $sql = <<<'SQL'
            INSERT INTO public.stores (
                vendor_profile_id,
                name,
                slug,
                description,
                status,
                created_at,
                updated_at
            )
            VALUES (
                :vendorProfileId,
                :name,
                :slug,
                :description,
                'pending',
                NOW(),
                NOW()
            )
            RETURNING
                id,
                vendor_profile_id,
                name,
                slug,
                description,
                status,
                created_at,
                updated_at
        SQL;

        $row = $this->db->fetchOne(
            $sql,
            Enum::FETCH_ASSOC,
            [
                'vendorProfileId' => $vendorProfileId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
            ],
        );

        if (!is_array($row) || $row === []) {
            throw new \RuntimeException(
                'The store could not be retrieved after creation.',
            );
        }

        return $row;
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
                SELECT
                    id,
                    vendor_profile_id,
                    name,
                    slug,
                    description,
                    status,
                    created_at,
                    updated_at
                FROM public.stores
                WHERE slug = :slug
                LIMIT 1
            SQL,
            Enum::FETCH_ASSOC,
            ['slug' => $slug],
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    public function slugExists(string $slug): bool
    {
        return $this->findBySlug($slug) !== null;
    }

    public function findPublicById(string $id): ?array
    {
        $row = $this->db->fetchOne(
            <<<'SQL'
            SELECT
                id,
                vendor_profile_id,
                name,
                slug,
                description,
                status,
                created_at,
                updated_at
            FROM public.stores
            WHERE id = :id
              AND status = 'active'
            LIMIT 1
        SQL,
            Enum::FETCH_ASSOC,
            ['id' => $id],
        );

        return is_array($row) && $row !== [] ? $row : null;
    }

    public function listPublic(int $limit, int $offset): array
    {
        // Bound pagination values to reasonable limits.
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $sql = sprintf(
            <<<'SQL'
            SELECT
                id,
                vendor_profile_id,
                name,
                slug,
                description,
                status,
                created_at,
                updated_at
            FROM public.stores
            WHERE status = 'active'
            ORDER BY created_at DESC, id DESC
            LIMIT %d OFFSET %d
        SQL,
            $limit,
            $offset,
        );

        return $this->db->fetchAll($sql,Enum::FETCH_ASSOC);
    }

    public function findByVendorProfileId(
        string $vendorProfileId,
    ): array {
        return $this->db->fetchAll(
            <<<'SQL'
            SELECT
                id,
                vendor_profile_id,
                name,
                slug,
                description,
                status,
                created_at,
                updated_at
            FROM public.stores
            WHERE vendor_profile_id = :vendorProfileId
            ORDER BY created_at DESC, id DESC
        SQL,
            Enum::FETCH_ASSOC,
            ['vendorProfileId' => $vendorProfileId],
        );
    }
}