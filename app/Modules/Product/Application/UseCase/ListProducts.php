<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\Repositories\ProductRepository;

final readonly class ListProducts
{
    public function __construct(private ProductRepository $products)
    {
    }

    /** @return list<array<string, mixed>> */
    public function execute(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        // return array_map(
        //     static fn ($product): array => [
        //         'id' => $product->id,
        //         'vendor_id' => $product->vendorId,
        //         'name' => $product->name,
        //         'slug' => $product->slug,
        //         'price_minor' => $product->priceMinor,
        //         'currency' => $product->currency,
        //     ],
        //     $this->products->findPublished($limit, $offset)
        // );
        return [
            [
                'id' => '1',
                'vendor_id' => '1',
                'name' => 'Product 1',
                'slug' => 'product-1',
                'price_minor' => 1000,
                'currency' => 'USD',
            ],
            [
                'id' => '2',
                'vendor_id' => '1',
                'name' => 'Product 2',
                'slug' => 'product-2',
                'price_minor' => 2000,
                'currency' => 'USD',
            ],
        ];
    }
}
