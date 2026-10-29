<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use App\Modules\Product\Application\Dtos\CreateProductDto;
use App\Modules\Product\Application\Dtos\UpdateProductDto;
use App\Modules\Product\Application\Exceptions\ProductNotFoundException;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Entities\Product;
use App\Shared\Http\AuthenticationRequiredException;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use Phalcon\Mvc\Controller;
use RuntimeException;
use Throwable;

final class ProductController extends Controller
{
    public function createAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $data = $this->request->getJsonRawBody(true);

            if (!is_array($data)) {
                throw new InvalidArgumentException(
                    'A valid JSON request body is required.'
                );
            }

            $dto = CreateProductDto::fromArray($data);

            $product = $this->productService()->create($userId, $dto);

            return $this->jsonResponder->success(
                ['product' => $this->serializeProduct($product)],
                201
            );
        } catch (AuthenticationRequiredException $e) {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                $e->getMessage(),
                401
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'INVALID_REQUEST',
                $e->getMessage(),
                400
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $e->getMessage(),
                409
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to create product.',
                500
            );
        }
    }

    public function showAction()
    {
        try {
            $productId = (string) $this->dispatcher->getParameter('id');

            if (trim($productId) === '') {
                throw new InvalidArgumentException(
                    'Product ID is required.'
                );
            }

            $product = $this->productService()->getById($productId);

            return $this->jsonResponder->success([
                'product' => $this->serializeProduct($product),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                $e->getMessage(),
                400
            );
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product not found.',
                404
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve product.',
                500
            );
        }
    }

    public function updateAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = (string) $this->dispatcher->getParameter('id');
            $data = $this->request->getJsonRawBody(true);

            if (trim($productId) === '') {
                throw new InvalidArgumentException(
                    'Product ID is required.'
                );
            }

            if (!is_array($data)) {
                throw new InvalidArgumentException(
                    'A valid JSON request body is required.'
                );
            }

            $dto = UpdateProductDto::fromArray($data);

            $product = $this->productService()->update(
                $userId,
                $productId,
                $dto
            );

            return $this->jsonResponder->success([
                'product' => $this->serializeProduct($product),
            ]);
        } catch (AuthenticationRequiredException $e) {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                $e->getMessage(),
                401
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                $e->getMessage(),
                400
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product not found.',
                404
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to update product.',
                500
            );
        }
    }

    public function archiveAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = (string) $this->dispatcher->getParameter('id');

            if (trim($productId) === '') {
                throw new InvalidArgumentException(
                    'Product ID is required.'
                );
            }

            $product = $this->productService()->archive($userId, $productId);

            return $this->jsonResponder->success([
                'product' => $this->serializeProduct($product),
            ]);
        } catch (AuthenticationRequiredException $e) {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                $e->getMessage(),
                401
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                $e->getMessage(),
                400
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product not found.',
                404
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to archive product.',
                500
            );
        }
    }


    public function manageShowAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = (string) $this->dispatcher->getParameter('id');

            if (trim($productId) === '') {
                throw new InvalidArgumentException(
                    'Product ID is required.'
                );
            }

            $product = $this->productService()->getManagedById(
                $userId,
                $productId
            );

            return $this->jsonResponder->success([
                'product' => $this->serializeProduct($product),
            ]);
        } catch (AuthenticationRequiredException $e) {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                $e->getMessage(),
                401
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                $e->getMessage(),
                400
            );
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product not found.',
                404
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve managed product.',
                500
            );
        }
    }


    private function productService(): ProductService
    {
        return $this->di->getShared(ProductService::class);
    }

    private function authenticatedUserId(): string
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || trim($userId) === '') {
            throw new AuthenticationRequiredException(
                'Authentication is required.'
            );
        }

        return $userId;
    }

    private function serializeProduct(Product $product): array
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
            'published_at' => $this->formatDate($product->publishedAt),
            'created_at' => $this->formatDate($product->createdAt),
            'updated_at' => $this->formatDate($product->updatedAt),
        ];
    }

    private function formatDate(?DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }

    private function logException(Throwable $e): void
    {
        try {
            $this->getDI()
                ->getShared('logger')
                ->error($e->getMessage());
        } catch (Throwable) {
            // Logging must not prevent the API from returning its error response.
        }
    }
}
