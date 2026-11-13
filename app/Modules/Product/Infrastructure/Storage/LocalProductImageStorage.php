<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Storage;

use App\Modules\Product\Application\Contracts\ProductImageStorageInterface;
use RuntimeException;

final readonly class LocalProductImageStorage implements ProductImageStorageInterface
{
    private string $storageRoot;

    public function __construct(string $storageRoot)
    {
        $this->storageRoot = rtrim($storageRoot, DIRECTORY_SEPARATOR);
    }

    public function store(string $processedFilePath): string
    {
        if (!is_file($processedFilePath) || !is_readable($processedFilePath)) {
            throw new RuntimeException('The processed image file does not exist.');
        }

        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'products';

        if (
            !is_dir($directory)
            && !mkdir($directory, 0755, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException('Unable to create the product image directory.');
        }

        $filename = bin2hex(random_bytes(16)) . '.webp';
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $temporaryDestination = $destination . '.tmp';

        if (!copy($processedFilePath, $temporaryDestination)) {
            throw new RuntimeException('Unable to copy the processed image.');
        }

        if (!rename($temporaryDestination, $destination)) {
            @unlink($temporaryDestination);
            throw new RuntimeException('Unable to finalize the stored image.');
        }

        return 'products/' . $filename;
    }

    public function delete(string $objectKey): void
    {
        $absolutePath = $this->getPathFromKey($objectKey);

        if (is_file($absolutePath) && !unlink($absolutePath)) {
            throw new RuntimeException('Unable to delete the stored product image.');
        }
    }

    public function getAbsolutePath(string $objectKey): ?string
    {
        $path = $this->getPathFromKey($objectKey);

        return is_file($path) && is_readable($path)
            ? $path
            : null;
    }

    private function getPathFromKey(string $objectKey): string
    {
        if (!preg_match('/\Aproducts\/[a-f0-9]{32}\.webp\z/', $objectKey)) {
            throw new RuntimeException('Invalid product image storage key.');
        }

        return $this->storageRoot
            . DIRECTORY_SEPARATOR
            . 'products'
            . DIRECTORY_SEPARATOR
            . basename($objectKey);
    }
}
