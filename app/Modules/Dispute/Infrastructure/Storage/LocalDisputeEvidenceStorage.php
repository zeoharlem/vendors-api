<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Storage;

use App\Modules\Dispute\Domain\Contracts\DisputeEvidenceStorageInterface;
use InvalidArgumentException;
use RuntimeException;

final readonly class LocalDisputeEvidenceStorage implements DisputeEvidenceStorageInterface
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private string $storageDirectory
    ) {
        if (
            $this->storageDirectory === ''
            || !str_starts_with($this->storageDirectory, DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException(
                'Evidence storage directory must be an absolute path.'
            );
        }
    }

    public function store(
        string $sourcePath,
        string $storageKey,
        string $mimeType
    ): void {
        $this->validateStorageKey($storageKey);

        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException(
                'Unsupported dispute evidence MIME type.'
            );
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException(
                'Evidence source file does not exist or is not readable.'
            );
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMimeType = $fileInfo->file($sourcePath);

        if ($actualMimeType !== $mimeType) {
            throw new InvalidArgumentException(
                'Evidence file MIME type does not match its declared MIME type.'
            );
        }

        $root = $this->ensureStorageDirectory();
        $targetPath = $this->resolvePath($root, $storageKey, true);

        if (file_exists($targetPath)) {
            throw new RuntimeException(
                'Evidence storage key already exists.'
            );
        }

        $temporaryPath = dirname($targetPath)
            . DIRECTORY_SEPARATOR
            . '.tmp-'
            . bin2hex(random_bytes(16));

        $source = fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new RuntimeException(
                'Unable to open evidence source file.'
            );
        }

        $destination = null;

        try {
            $destination = fopen($temporaryPath, 'xb');

            if ($destination === false) {
                throw new RuntimeException(
                    'Unable to create temporary evidence file.'
                );
            }

            $copiedBytes = stream_copy_to_stream(
                $source,
                $destination
            );

            if ($copiedBytes === false) {
                throw new RuntimeException(
                    'Unable to copy evidence file.'
                );
            }

            if (!fflush($destination)) {
                throw new RuntimeException(
                    'Unable to flush evidence file.'
                );
            }

            if (!chmod($temporaryPath, 0600)) {
                throw new RuntimeException(
                    'Unable to secure evidence file permissions.'
                );
            }

            if (file_exists($targetPath)) {
                throw new RuntimeException(
                    'Evidence storage key already exists.'
                );
            }

            if (!rename($temporaryPath, $targetPath)) {
                throw new RuntimeException(
                    'Unable to finalize evidence file storage.'
                );
            }
        } finally {
            fclose($source);

            if (is_resource($destination)) {
                fclose($destination);
            }

            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @return resource|false
     */
    public function read(string $storageKey): mixed
    {
        $this->validateStorageKey($storageKey);

        $root = $this->ensureStorageDirectory();
        $path = $this->resolvePath($root, $storageKey, false);

        if (
            !is_file($path)
            || is_link($path)
            || !$this->isInsideStorageDirectory($root, $path)
        ) {
            return false;
        }

        return fopen($path, 'rb');
    }

    public function delete(string $storageKey): void
    {
        $this->validateStorageKey($storageKey);

        $root = $this->ensureStorageDirectory();
        $path = $this->resolvePath($root, $storageKey, false);

        if (!file_exists($path)) {
            return;
        }

        if (
            is_link($path)
            || !is_file($path)
            || !$this->isInsideStorageDirectory($root, $path)
        ) {
            throw new RuntimeException(
                'Invalid evidence storage file.'
            );
        }

        if (!unlink($path)) {
            throw new RuntimeException(
                'Unable to delete dispute evidence file.'
            );
        }
    }

    public function exists(string $storageKey): bool
    {
        $this->validateStorageKey($storageKey);

        $root = $this->ensureStorageDirectory();
        $path = $this->resolvePath($root, $storageKey, false);

        return is_file($path)
            && !is_link($path)
            && $this->isInsideStorageDirectory($root, $path);
    }

    private function ensureStorageDirectory(): string
    {
        if (!is_dir($this->storageDirectory)) {
            if (
                !mkdir($this->storageDirectory, 0700, true)
                && !is_dir($this->storageDirectory)
            ) {
                throw new RuntimeException(
                    'Unable to create evidence storage directory.'
                );
            }
        }

        $realPath = realpath($this->storageDirectory);

        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException(
                'Evidence storage directory is unavailable.'
            );
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR)
            ?: DIRECTORY_SEPARATOR;
    }

    private function resolvePath(
        string $root,
        string $storageKey,
        bool $createDirectories
    ): string {
        $segments = explode('/', $storageKey);
        $filename = array_pop($segments);

        $directory = $root;

        foreach ($segments as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;

            if (!is_dir($directory) && $createDirectories) {
                if (
                    !mkdir($directory, 0700)
                    && !is_dir($directory)
                ) {
                    throw new RuntimeException(
                        'Unable to create evidence storage subdirectory.'
                    );
                }
            }

            if (is_link($directory)) {
                throw new RuntimeException(
                    'Evidence storage path contains a symbolic link.'
                );
            }

            if (is_dir($directory)) {
                $realDirectory = realpath($directory);

                if (
                    $realDirectory === false
                    || !$this->isInsideStorageDirectory(
                        $root,
                        $realDirectory
                    )
                ) {
                    throw new RuntimeException(
                        'Evidence storage path escapes its storage directory.'
                    );
                }
            } elseif (!$createDirectories) {
                return $directory . DIRECTORY_SEPARATOR . $filename;
            }
        }

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    private function validateStorageKey(string $storageKey): void
    {
        if (
            $storageKey === ''
            || strlen($storageKey) > 512
            || str_contains($storageKey, '\\')
            || str_contains($storageKey, "\0")
            || str_starts_with($storageKey, '/')
        ) {
            throw new InvalidArgumentException(
                'Invalid evidence storage key.'
            );
        }

        $segments = explode('/', $storageKey);

        foreach ($segments as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || str_starts_with($segment, '.')
                || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $segment)
            ) {
                throw new InvalidArgumentException(
                    'Invalid evidence storage key.'
                );
            }
        }
    }

    private function isInsideStorageDirectory(
        string $root,
        string $path
    ): bool {
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR;

        return str_starts_with(
            $path,
            $rootPrefix
        );
    }
}
