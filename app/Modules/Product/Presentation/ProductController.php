<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;
use Phalcon\Mvc\Controller;

final class ProductController extends Controller
{
    public function indexAction()
    {
        return $this->jsonResponder->success(
            [
            'products' => [
                    [
                        'id' => '1',
                        'name' => 'Product 1',
                        'description' => 'Description for Product 1',
                        'price' => 10.99,
                    ],
                    [
                        'id' => '2',
                        'name' => 'Product 2',
                        'description' => 'Description for Product 2',
                        'price' => 19.99,
                    ],
                ],
            ]
        );
    }

    public function showAction(string $id)
    {
        return $this->jsonResponder->success(
            [
                'product' => [
                    'id' => $id,
                    'name' => 'Product ' . $id,
                    'description' => 'Description for Product ' . $id,
                    'price' => 10.99 + (int)$id,
                ],
            ]
        );
    }
}
