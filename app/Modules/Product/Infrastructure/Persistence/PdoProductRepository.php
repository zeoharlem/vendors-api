<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Entities\Product;
use App\Modules\Product\Domain\Enums\ProductModerationStatus;
use App\Modules\Product\Domain\Enums\ProductStatus;
use App\Modules\Product\Domain\Repositories\ProductRepository;
use DateTimeImmutable;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Phalcon\Db\Enum;
use RuntimeException;

final readonly class PdoProductRepository implements ProductRepository
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function create(Product $product): Product
    {
        $sql = <<<'SQL'
            INSERT INTO public.products (
                id,
                store_id,
                category_id,
                name,
                slug,
                description,
                status,
                moderation_status,
                published_at,
                created_at,
                updated_at
            )
            VALUES (
                :id,
                :store_id,
                :category_id,
                :name,
                :slug,
                :description,
                :status,
                :moderation_status,
                :published_at,
                :created_at,
                :updated_at
            )
            RETURNING *
        SQL;

        $row = $this->fetchOne($sql, $this->toParameters($product));

        if ($row === null) {
            throw new RuntimeException(
                'The product could not be created.'
            );
        }

        return $this->hydrate($row);
    }

    public function update(Product $product): Product
    {
        $sql = <<<'SQL'
            UPDATE public.products
            SET
                category_id = :category_id,
                name = :name,
                slug = :slug,
                description = :description,
                status = :status,
                moderation_status = :moderation_status,
                published_at = :published_at,
                updated_at = :updated_at
            WHERE id = :id
              AND store_id = :store_id
            RETURNING *
        SQL;

        $row = $this->fetchOne($sql, $this->toParameters($product));

        if ($row === null) {
            throw new RuntimeException(
                'The product was not found for this store or could not be updated.'
            );
        }

        return $this->hydrate($row);
    }

    public function findById(string $id): ?Product
    {
        $sql = <<<'SQL'
            SELECT *
            FROM public.products
            WHERE id = :id
            LIMIT 1
        SQL;

        $row = $this->fetchOne($sql, ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }


    public function findPublishedById(string $id): ?Product
    {
        $sql = <<<'SQL'
            SELECT p.*
            FROM public.products p
            INNER JOIN public.stores s
                ON s.id = p.store_id
            INNER JOIN public.vendor_profiles vp
                ON vp.id = s.vendor_profile_id
            WHERE p.id = :id
            AND p.status = :product_status
            AND p.moderation_status = :moderation_status
            AND p.published_at IS NOT NULL
            AND p.published_at <= CURRENT_TIMESTAMP
            AND s.status = :store_status
            AND vp.verification_status = :verification_status
            LIMIT 1
        SQL;

        $row = $this->fetchOne($sql, [
            'id' => $id,
            'product_status' => ProductStatus::PUBLISHED->value,
            'moderation_status' => ProductModerationStatus::APPROVED->value,
            'store_status' => 'active',
            'verification_status' => 'verified',
        ]);

        return $row === null ? null : $this->hydrate($row);
    }


    public function findByStoreAndSlug(
        string $storeId,
        string $slug
    ): ?Product {
        $sql = <<<'SQL'
            SELECT *
            FROM public.products
            WHERE store_id = :store_id
              AND slug = :slug
            LIMIT 1
        SQL;

        $row = $this->fetchOne($sql, [
            'store_id' => $storeId,
            'slug' => $slug,
        ]);

        return $row === null ? null : $this->hydrate($row);
    }

    private function fetchOne(string $sql, array $parameters): ?array
    {
        $result = $this->db->query($sql, $parameters);
        $row = $result->fetch();

        return is_array($row) ? $row : null;
    }

    private function toParameters(Product $product): array
    {
        return [
            'id' => $product->id,
            'store_id' => $product->storeId,
            'category_id' => $product->categoryId,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'status' => $product->status->value,
            'moderation_status' => $product->moderationStatus->value,
            'published_at' => $product->publishedAt?->format('Y-m-d H:i:sP'),
            'created_at' => $product->createdAt->format('Y-m-d H:i:sP'),
            'updated_at' => $product->updatedAt->format('Y-m-d H:i:sP'),
        ];
    }

    private function hydrate(array $row): Product
    {
        return new Product(
            id: (string) $row['id'],
            storeId: (string) $row['store_id'],
            categoryId: $row['category_id'] !== null
            ? (string) $row['category_id']
            : null,
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: $row['description'] !== null
            ? (string) $row['description']
            : null,
            status: ProductStatus::from((string) $row['status']),
            moderationStatus: ProductModerationStatus::from(
                (string) $row['moderation_status']
            ),
            publishedAt: $this->parseDate($row['published_at']),
            createdAt: $this->parseDate($row['created_at'])
            ?? throw new RuntimeException(
                'Product created_at cannot be null.'
            ),
            updatedAt: $this->parseDate($row['updated_at'])
            ?? throw new RuntimeException(
                'Product updated_at cannot be null.'
            ),
        );
    }

    private function formatDate(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s.uP');
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return new DateTimeImmutable((string) $value);
    }
}
