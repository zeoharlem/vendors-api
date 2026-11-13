<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\Contracts\ProductImageProcessorInterface;
use App\Modules\Product\Application\Contracts\ProductImageStorageInterface;
use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use App\Modules\Product\Domain\Entities\ProductMedia;
use App\Modules\Product\Domain\Repositories\ProductMediaRepository;
use App\Modules\Product\Domain\Repositories\ProductRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use App\Modules\Product\Domain\Entities\Product;
use App\Modules\Product\Application\Exceptions\ProductNotFoundException;
use DomainException;

final readonly class ProductImageService
{
    private const MAX_IMAGES_PER_PRODUCT = 5;

    public function __construct(
        private ProductRepository $productRepository,
        private ProductMediaRepository $productMediaRepository,
        private StoreAccessCheckerInterface $storeAccessChecker,
        private ProductImageProcessorInterface $imageProcessor,
        private ProductImageStorageInterface $imageStorage,
        private AdapterInterface $db,
    ) {
    }

    /**
     * @param array<int, array{tmp_name: string, alt_text?: ?string}> $uploads
     * @return ProductMedia[]
     */
    public function upload(
        string $productId,
        string $userId,
        array $uploads
    ): array {
        $this->getAccessibleProduct($productId, $userId);

        if ($uploads === []) {
            throw new InvalidArgumentException('At least one image is required.');
        }

        if (count($uploads) > self::MAX_IMAGES_PER_PRODUCT) {
            throw new InvalidArgumentException(
                'A product cannot have more than five images.'
            );
        }

        $storedKeys = [];
        $createdMedia = [];
        $transactionStarted = false;

        try {
            $this->db->begin();
            $transactionStarted = true;

            // Serialize image uploads for this product.
            $result = $this->db->query(
                <<<'SQL'
                    SELECT id
                    FROM public.products
                    WHERE id = :product_id
                    FOR UPDATE
                SQL,
                ['product_id' => $productId]
            );

            if ($result->fetch() === false) {
                throw new ProductNotFoundException('Product not found.');
            }

            // Count only after acquiring the product lock.
            $existingCount = $this->productMediaRepository->countByProductId($productId);

            if ($existingCount + count($uploads) > self::MAX_IMAGES_PER_PRODUCT) {
                throw new InvalidArgumentException(
                    'A product cannot have more than five images.'
                );
            }

            foreach ($uploads as $index => $upload) {
                $temporaryPath = $upload['tmp_name'] ?? null;

                if (!is_string($temporaryPath) || $temporaryPath === '') {
                    throw new InvalidArgumentException(
                        'An uploaded image has an invalid temporary path.'
                    );
                }

                $processedPath = null;

                try {
                    $processedPath = $this->imageProcessor->process($temporaryPath);

                    $objectKey = $this->imageStorage->store($processedPath);
                    $storedKeys[] = $objectKey;

                    $altText = $upload['alt_text'] ?? null;

                    if ($altText !== null && !is_string($altText)) {
                        throw new InvalidArgumentException(
                            'Image alt text must be a string.'
                        );
                    }

                    $media = new ProductMedia(
                        id: $this->generateUuid(),
                        productId: $productId,
                        objectKey: $objectKey,
                        altText: $altText,
                        sortOrder: $existingCount + $index,
                        createdAt: new DateTimeImmutable()
                    );

                    $createdMedia[] = $this->productMediaRepository->create($media);
                } finally {
                    if (
                        $processedPath !== null
                        && is_file($processedPath)
                    ) {
                        @unlink($processedPath);
                    }
                }
            }

            $this->db->commit();
            $transactionStarted = false;

            return $createdMedia;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $this->db->rollback();
                } catch (Throwable) {
                    // Preserve the original exception.
                }
            }

            // Database inserts have been rolled back.
            // Remove files written during this upload batch.
            foreach ($storedKeys as $objectKey) {
                try {
                    $this->imageStorage->delete($objectKey);
                } catch (Throwable) {
                    // Preserve the original exception.
                }
            }

            throw $exception;
        }
    }

    /**
     * @return ProductMedia[]
     */
    public function list(
        string $productId,
        string $userId
    ): array {
        $this->getAccessibleProduct($productId, $userId);

        return $this->productMediaRepository->findByProductId($productId);
    }

    public function updateDetails(
        string $productId,
        string $mediaId,
        string $userId,
        ?string $altText,
        int $sortOrder
    ): ProductMedia {
        $this->getAccessibleProduct($productId, $userId);

        if ($sortOrder < 0) {
            throw new InvalidArgumentException(
                'Image sort order cannot be negative.'
            );
        }

        if ($altText !== null && trim($altText) === '') {
            $altText = null;
        }

        $media = $this->productMediaRepository->updateDetails(
            $productId,
            $mediaId,
            $altText,
            $sortOrder
        );

        if ($media === null) {
            throw new ProductNotFoundException('Product image was not found.');
        }

        return $media;
    }

    public function delete(
        string $productId,
        string $mediaId,
        string $userId
    ): void {
        $this->getAccessibleProduct($productId, $userId);

        $media = $this->productMediaRepository->findById(
            $productId,
            $mediaId
        );

        if ($media === null) {
            throw new ProductNotFoundException('Product image was not found.');
        }

        $deleted = $this->productMediaRepository->delete(
            $productId,
            $mediaId
        );

        if (!$deleted) {
            throw new ProductNotFoundException('Unable to delete product image.');
        }

        // Delete the physical file only after the database row is deleted.
        $this->imageStorage->delete($media->objectKey);
    }

    public function getImage(
        string $productId,
        string $imageId
    ): ?array {
        $product = $this->productRepository->findPublishedById($productId);

        if ($product === null) {
            return null;
        }

        $media = $this->productMediaRepository->findById($productId,$imageId);

        if ($media === null) {
            return null;
        }

        $absolutePath = $this->imageStorage->getAbsolutePath($media->objectKey);

        if (
            $absolutePath === null
            || !is_file($absolutePath)
            || !is_readable($absolutePath)
        ) {
            return null;
        }

        return [
            'path' => $absolutePath,
            'mime_type' => 'image/webp',
        ];
    }

    public function replace(
        string $productId,
        string $mediaId,
        string $userId,
        string $uploadedFilePath,
    ): ProductMedia {
        $this->getAccessibleProduct($productId, $userId);

        $media = $this->productMediaRepository->findById(
            $productId,
            $mediaId
        );

        if ($media === null) {
            throw new ProductNotFoundException('Product image not found.');
        }

        if (
            !is_file($uploadedFilePath)
            || !is_readable($uploadedFilePath)
        ) {
            throw new InvalidArgumentException('Invalid uploaded image.');
        }

        $processedFilePath = null;
        $newObjectKey = null;
        $databaseCommitted = false;

        try {
            $processedFilePath = $this->imageProcessor->process(
                $uploadedFilePath
            );

            $newObjectKey = $this->imageStorage->store(
                $processedFilePath
            );

            $this->db->begin();

            try {
                $updatedMedia = $this->productMediaRepository->replaceObjectKey(
                    $productId,
                    $mediaId,
                    $newObjectKey
                );

                if ($updatedMedia === null) {
                    throw new ProductNotFoundException(
                        'Product image not found.'
                    );
                }

                $this->db->commit();
                $databaseCommitted = true;
            } catch (Throwable $exception) {
                $this->db->rollback();
                throw $exception;
            }

            // The database now points to the new file.
            // Failure to remove the old file must not remove the new one.
            try {
                $this->imageStorage->delete($media->objectKey);
            } catch (Throwable) {
                // Old file can be cleaned up separately.
            }

            return $updatedMedia;
        } catch (Throwable $exception) {
            if (!$databaseCommitted && $newObjectKey !== null) {
                try {
                    $this->imageStorage->delete($newObjectKey);
                } catch (Throwable) {
                    // Preserve the original exception.
                }
            }

            throw $exception;
        } finally {
            if (
                $processedFilePath !== null
                && is_file($processedFilePath)
            ) {
                unlink($processedFilePath);
            }
        }
    }

    private function getAccessibleProduct(
        string $productId,
        string $userId
    ): Product {
        $product = $this->productRepository->findById($productId);

        if ($product === null) {
            throw new ProductNotFoundException('Product not found.');
        }

        if (
            !$this->storeAccessChecker->canManageStore(
                $userId,
                $product->storeId
            )
        ) {
            throw new DomainException(
                'You do not have permission to manage this product.'
            );
        }

        return $product;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
