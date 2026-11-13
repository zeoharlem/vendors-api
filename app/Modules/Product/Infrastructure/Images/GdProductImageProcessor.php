<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Images;

use App\Modules\Product\Application\Contracts\ProductImageProcessorInterface;
use finfo;
use InvalidArgumentException;
use RuntimeException;

final readonly class GdProductImageProcessor implements ProductImageProcessorInterface
{
    private const MAX_WIDTH = 2000;
    private const MAX_HEIGHT = 2000;
    private const MAX_PIXELS = 25_000_000;
    private const WEBP_QUALITY = 82;

    public function process(string $sourcePath): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new InvalidArgumentException('The uploaded image could not be read.');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($sourcePath);

        $imageType = match ($mimeType) {
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/webp' => IMAGETYPE_WEBP,
            default => throw new InvalidArgumentException(
                'Only JPEG, PNG and WebP images are supported.'
            ),
        };

        $imageInfo = @getimagesize($sourcePath);

        if ($imageInfo === false || ($imageInfo[2] ?? null) !== $imageType) {
            throw new InvalidArgumentException('The uploaded file is not a valid image.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('The image dimensions are invalid.');
        }

        if (($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException(
                'The image dimensions are too large to process.'
            );
        }

        $source = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default => false,
        };

        if ($source === false) {
            throw new InvalidArgumentException('The image could not be decoded.');
        }

        $scale = min(
            1,
            self::MAX_WIDTH / $width,
            self::MAX_HEIGHT / $height
        );

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $destination = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($destination === false) {
            imagedestroy($source);
            throw new RuntimeException('Unable to allocate image memory.');
        }

        imagealphablending($destination, false);
        imagesavealpha($destination, true);

        $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
        imagefill($destination, 0, 0, $transparent);

        $resampled = imagecopyresampled($destination,$source,0,0,0,0,$targetWidth,$targetHeight,$width,$height);

        imagedestroy($source);

        if (!$resampled) {
            imagedestroy($destination);
            throw new RuntimeException('Unable to resize the uploaded image.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'product-image-');

        if ($temporaryPath === false) {
            imagedestroy($destination);
            throw new RuntimeException('Unable to create a temporary image file.');
        }

        try {
            if (!imagewebp($destination, $temporaryPath, self::WEBP_QUALITY)) {
                throw new RuntimeException('Unable to convert the image to WebP.');
            }

            return $temporaryPath;
        } catch (\Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $exception;
        } finally {
            imagedestroy($destination);
        }
    }
}
