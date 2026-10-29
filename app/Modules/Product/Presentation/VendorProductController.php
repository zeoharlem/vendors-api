<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use App\Modules\Product\Application\Dtos\CreateProductDto;
use App\Modules\Product\Application\Services\ProductService;
use DomainException;
use InvalidArgumentException;
use Phalcon\Mvc\Controller;
use Throwable;

final class VendorProductController extends Controller
{
    private ProductService $productService;

    public function initialize(): void
    {
        $this->productService = $this->di->getShared('productService');
    }

    public function createAction(): mixed
    {
        try {
            $authenticatedUserId = $this->dispatcher->getParameter('userAuthId');

            if (
                !is_string($authenticatedUserId)
                || trim($authenticatedUserId) === ''
            ) {
                return $this->jsonResponder->error(
                    'UNAUTHENTICATED',
                    'An authenticated user is required.',
                    401
                );
            }

            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                return $this->jsonResponder->error(
                    'BAD_REQUEST',
                    'A valid JSON request body is required.',
                    400
                );
            }

            $dto = CreateProductDto::fromArray($payload);

            $product = $this->productService->create(
                $authenticatedUserId,
                $dto
            );

            return $this->jsonResponder->success([
                'product' => $product,
                'message' => 'Product submitted successfully for review.',
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $e->getMessage(),
                422
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $e->getMessage(),
                409
            );
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to submit the product.',
                500
            );
        }
    }
}