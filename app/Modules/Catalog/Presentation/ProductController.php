<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation;

use App\Modules\Catalog\Application\UseCase\ListProducts;
use App\Modules\Catalog\Infrastructure\Persistence\PdoProductRepository;
use App\Shared\Http\JsonResponder;
use Phalcon\Mvc\Controller;

final class ProductController extends Controller
{
    public function indexAction()
    {
        $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
        $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $repository = new PdoProductRepository($this->db);
        $useCase = new ListProducts($repository);

        return $this->jsonResponder->success($useCase->execute($limit ?: 20, $offset ?: 0), 200, [
            'limit' => max(1, min($limit ?: 20, 100)),
            'offset' => max(0, $offset ?: 0),
        ]);
    }

    public function showAction(string $id)
    {
        $repository = new PdoProductRepository($this->db);
        $product = $repository->findById($id);

        if ($product === null) {
            return $this->jsonResponder->error('PRODUCT_NOT_FOUND', 'Product was not found.', 404);
        }

        return $this->jsonResponder->success([
            'id' => $product->id,
            'vendor_id' => $product->vendorId,
            'name' => $product->name,
            'slug' => $product->slug,
            'price_minor' => $product->priceMinor,
            'currency' => $product->currency,
        ]);
    }
}
