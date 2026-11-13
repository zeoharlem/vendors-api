<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use App\Modules\Product\Application\Exceptions\ProductNotFoundException;
use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Domain\Entities\ProductMedia;
use App\Shared\Http\AuthenticationRequiredException;
use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use Phalcon\Mvc\Controller;
use Throwable;

final class ProductImageController extends Controller
{
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;
    private const MAX_IMAGES = 5;

    public function uploadAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = $this->productId();

            $files = $this->request->getUploadedFiles();

            if (!is_array($files) || $files === []) {
                throw new InvalidArgumentException(
                    'At least one image must be uploaded using the images[] field.'
                );
            }

            $uploads = [];

            foreach ($files as $file) {
                if (
                    !is_object($file)
                    || !method_exists($file, 'getError')
                    || !method_exists($file, 'getTempName')
                    || !method_exists($file, 'getSize')
                ) {
                    throw new InvalidArgumentException(
                        'Invalid uploaded file.'
                    );
                }

                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException(
                        'One or more images could not be uploaded.'
                    );
                }

                if ($file->getSize() > self::MAX_IMAGE_SIZE) {
                    throw new InvalidArgumentException(
                        'Each image must not exceed 10 MB.'
                    );
                }

                $tempName = $file->getTempName();

                if (
                    !is_string($tempName)
                    || $tempName === ''
                    || !is_file($tempName)
                    || !is_readable($tempName)
                ) {
                    throw new InvalidArgumentException(
                        'An uploaded image is not accessible.'
                    );
                }

                $uploads[] = [
                    'tmp_name' => $tempName,
                ];
            }

            if (count($uploads) > self::MAX_IMAGES) {
                throw new InvalidArgumentException(
                    'You can upload a maximum of five images per request.'
                );
            }

            $images = $this->imageService()->upload(
                $productId,
                $userId,
                $uploads
            );

            return $this->jsonResponder->success([
                'message' => 'Product images uploaded successfully.',
                'images' => array_map(
                    fn(ProductMedia $image): array => $this->serializeImage($image),
                    $images
                ),
            ], 201);

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
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product not found.',
                404
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to upload product images.',
                500
            );
        }
    }

    public function indexAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = $this->productId();

            $images = $this->imageService()->list(
                $productId,
                $userId
            );

            return $this->jsonResponder->success([
                'images' => array_map(
                    fn(ProductMedia $image): array => $this->serializeImage($image),
                    $images
                ),
            ]);

        } catch (AuthenticationRequiredException $e) {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                $e->getMessage(),
                401
            );
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product not found.',
                404
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve product images.',
                500
            );
        }
    }

    public function updateAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = $this->productId();
            $imageId = $this->imageId();

            $data = $this->request->getJsonRawBody(true);

            if (!is_array($data)) {
                throw new InvalidArgumentException(
                    'A valid JSON request body is required.'
                );
            }

            $altText = $data['alt_text'] ?? null;
            $sortOrder = $data['sort_order'] ?? null;

            if ($altText !== null && !is_string($altText)) {
                throw new InvalidArgumentException(
                    'alt_text must be a string or null.'
                );
            }

            if (!is_int($sortOrder) || $sortOrder < 0) {
                throw new InvalidArgumentException(
                    'sort_order must be a non-negative integer.'
                );
            }

            $image = $this->imageService()->updateDetails(
                $productId,
                $imageId,
                $userId,
                $altText,
                $sortOrder
            );

            return $this->jsonResponder->success([
                'message' => 'Product image updated successfully.',
                'image' => $this->serializeImage($image),
            ]);

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
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product or image not found.',
                404
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to update product image.',
                500
            );
        }
    }

    public function deleteAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = $this->productId();
            $imageId = $this->imageId();

            $this->imageService()->delete(
                $productId,
                $imageId,
                $userId
            );

            return $this->jsonResponder->success([
                'message' => 'Product image deleted successfully.',
            ]);

        } catch (AuthenticationRequiredException $e) {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                $e->getMessage(),
                401
            );
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product or image not found.',
                404
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to delete product image.',
                500
            );
        }
    }

    public function fileAction()
    {
        try {
            $image = $this->imageService()->getImage(
                $this->productId(),
                $this->imageId()
            );

            if ($image === null) {
                return $this->response
                    ->setStatusCode(404)
                    ->setContent('Image not found');
            }

            $content = file_get_contents($image['path']);

            if ($content === false) {
                return $this->response
                    ->setStatusCode(404)
                    ->setContent('Image not found');
            }

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', $image['mime_type'])
                ->setHeader(
                    'Cache-Control',
                    //'public, max-age=31536000, immutable',
                    'public, max-age=300, must-revalidate'
                )
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setContent($content);
        } catch (Throwable $exception) {
            $this->logException($exception);

            return $this->response
                ->setStatusCode(500)
                ->setContent('Unable to retrieve image');
        }
    }

    public function replaceAction()
    {
        try {
            $userId = $this->authenticatedUserId();
            $productId = $this->productId();
            $imageId = $this->imageId();

            $files = $this->request->getUploadedFiles();

            if (!is_array($files) || count($files) !== 1) {
                throw new InvalidArgumentException(
                    'Upload exactly one image using multipart/form-data.'
                );
            }

            $file = $files[0];

            if (
                !is_object($file)
                || !method_exists($file, 'getError')
                || !method_exists($file, 'getTempName')
                || !method_exists($file, 'getSize')
            ) {
                throw new InvalidArgumentException('Invalid uploaded file.');
            }

            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Image upload failed.');
            }

            if ($file->getSize() > self::MAX_IMAGE_SIZE) {
                throw new InvalidArgumentException(
                    'Image size must not exceed 10 MB.'
                );
            }

            $tempName = $file->getTempName();

            if (
                !is_string($tempName)
                || $tempName === ''
                || !is_file($tempName)
                || !is_readable($tempName)
            ) {
                throw new InvalidArgumentException('Invalid uploaded image.');
            }

            $image = $this->imageService()->replace(
                $productId,
                $imageId,
                $userId,
                $tempName
            );

            return $this->jsonResponder->success([
                'message' => 'Product image replaced successfully.',
                'image' => $this->serializeImage($image),
            ]);
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
        } catch (ProductNotFoundException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Product or image not found.',
                404
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $e->getMessage(),
                403
            );
        } catch (Throwable $e) {
            $this->logException($e);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to replace product image.',
                500
            );
        }
    }

    private function imageService(): ProductImageService
    {
        return $this->di->getShared('productImageService');
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

    private function productId(): string
    {
        $productId = $this->dispatcher->getParameter('id');

        if (!is_string($productId) || trim($productId) === '') {
            throw new InvalidArgumentException(
                'Product ID is required.'
            );
        }

        return $productId;
    }

    private function imageId(): string
    {
        $imageId = $this->dispatcher->getParameter('imageId');

        if (!is_string($imageId) || trim($imageId) === '') {
            throw new InvalidArgumentException(
                'Image ID is required.'
            );
        }

        return $imageId;
    }

    private function serializeImage(ProductMedia $image): array
    {
        return [
            'id' => $image->id,
            'product_id' => $image->productId,
            'url' => sprintf(
                '/api/v1/products/%s/images/%s/file',
                rawurlencode($image->productId),
                rawurlencode($image->id)
            ),
            'alt_text' => $image->altText,
            'sort_order' => $image->sortOrder,
            'created_at' => $image->createdAt->format(DATE_ATOM),
        ];
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
