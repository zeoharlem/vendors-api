<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Contracts;

interface ProductImageProcessorInterface
{
    /**
     * Process an uploaded image and return the path to a temporary WebP file.
     *
     * The caller is responsible for deleting the returned temporary file.
     */
    public function process(string $sourcePath): string;
}
