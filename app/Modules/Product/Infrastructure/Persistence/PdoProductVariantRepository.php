<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Entities\ProductVariant;
use App\Modules\Product\Domain\Repositories\ProductVariantRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoProductVariantRepository implements ProductVariantRepository
{
    public function __construct(private AdapterInterface $db)
    {
    }

    public function create(ProductVariant $variant): void
    {
        $sql = <<<'SQL'
            INSERT INTO public.product_variants (
                id,
                product_id,
                sku,
                name,
                attributes,
                price_minor,
                compare_at_minor,
                currency,
                status
            ) VALUES (
                :id,
                :product_id,
                :sku,
                :name,
                CAST(:attributes AS JSONB),
                :price_minor,
                :compare_at_minor,
                :currency,
                :status
            )
        SQL;

        $result = $this->db->query($sql, [
            'id' => $variant->id,
            'product_id' => $variant->productId,
            'sku' => $variant->sku,
            'name' => $variant->name,
            'attributes' => json_encode(
                $variant->attributes,
                JSON_THROW_ON_ERROR
            ),
            'price_minor' => $variant->priceMinor,
            'compare_at_minor' => $variant->compareAtMinor,
            'currency' => $variant->currency,
            'status' => $variant->status,
        ]);

        if ($result === false) {
            throw new RuntimeException('Failed to create product variant.');
        }
    }
}