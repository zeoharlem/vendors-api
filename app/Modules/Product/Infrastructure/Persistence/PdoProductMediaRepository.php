<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Entities\ProductMedia;
use App\Modules\Product\Domain\Repositories\ProductMediaRepository;
use DateTimeImmutable;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;

final readonly class PdoProductMediaRepository implements ProductMediaRepository
{
    public function __construct(
        private AdapterInterface $db
    ) {
    }

    public function create(ProductMedia $media): ProductMedia
    {
        $result = $this->db->query(
            <<<'SQL'
            INSERT INTO public.product_media (
                id,
                product_id,
                object_key,
                alt_text,
                sort_order,
                created_at
            )
            VALUES (
                :id,
                :product_id,
                :object_key,
                :alt_text,
                :sort_order,
                :created_at
            )
            RETURNING *
            SQL,
            [
                'id' => $media->id,
                'product_id' => $media->productId,
                'object_key' => $media->objectKey,
                'alt_text' => $media->altText,
                'sort_order' => $media->sortOrder,
                'created_at' => $media->createdAt->format('Y-m-d H:i:sP'),
            ]
        );

        $row = $result->fetch();

        if (!$row) {
            throw new \RuntimeException('Unable to create product image record.');
        }

        return $this->hydrate($row);
    }

    public function findByProductId(string $productId): array
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT *
            FROM public.product_media
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, created_at ASC
            SQL,
            ['product_id' => $productId]
        );

        $rows = $result->fetchAll();

        return array_map(
            fn(array $row): ProductMedia => $this->hydrate($row),
            $rows
        );
    }

    public function findById(
        string $productId,
        string $mediaId
    ): ?ProductMedia {
        $result = $this->db->query(
            <<<'SQL'
            SELECT *
            FROM public.product_media
            WHERE product_id = :product_id
              AND id = :id
            LIMIT 1
            SQL,
            [
                'product_id' => $productId,
                'id' => $mediaId,
            ]
        );

        $row = $result->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function updateDetails(
        string $productId,
        string $mediaId,
        ?string $altText,
        int $sortOrder
    ): ?ProductMedia {
        $result = $this->db->query(
            <<<'SQL'
            UPDATE public.product_media
            SET
                alt_text = :alt_text,
                sort_order = :sort_order
            WHERE product_id = :product_id
              AND id = :id
            RETURNING *
            SQL,
            [
                'alt_text' => $altText,
                'sort_order' => $sortOrder,
                'product_id' => $productId,
                'id' => $mediaId,
            ]
        );

        $row = $result->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function delete(
        string $productId,
        string $mediaId
    ): bool {
        $result = $this->db->query(
            <<<'SQL'
            DELETE FROM public.product_media
            WHERE product_id = :product_id
              AND id = :id
            RETURNING id
            SQL,
            [
                'product_id' => $productId,
                'id' => $mediaId,
            ]
        );

        return (bool) $result->fetch();
    }

    public function countByProductId(string $productId): int
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT COUNT(*) AS total
            FROM public.product_media
            WHERE product_id = :product_id
            SQL,
            ['product_id' => $productId]
        );

        $row = $result->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function replaceObjectKey(
        string $productId,
        string $mediaId,
        string $objectKey,
    ): ?ProductMedia {
        $result = $this->db->query(
            <<<'SQL'
                UPDATE public.product_media
                SET object_key = :object_key
                WHERE product_id = :product_id
                AND id = :media_id
                RETURNING *
            SQL,
            [
                'object_key' => $objectKey,
                'product_id' => $productId,
                'media_id' => $mediaId,
            ]
        );

        $row = $result->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): ProductMedia
    {
        return new ProductMedia(
            id: (string) $row['id'],
            productId: (string) $row['product_id'],
            objectKey: (string) $row['object_key'],
            altText: $row['alt_text'] !== null
            ? (string) $row['alt_text']
            : null,
            sortOrder: (int) $row['sort_order'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
