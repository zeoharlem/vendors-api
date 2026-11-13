<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Contracts;

interface ProductImageStorageInterface
{
    /**
     * Store a processed image and return its storage key.
     */
    public function store(string $processedFilePath): string;

    /**
     * Delete an image by its storage key.
     */
    public function delete(string $objectKey): void;

    /**
     * Resolve a storage key to a local file path.
     * Returns null if the image does not exist.
     */
    public function getAbsolutePath(string $objectKey): ?string;
}
