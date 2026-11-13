<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Domain\Contracts;

interface DisputeEvidenceStorageInterface
{
    /**
     * Stores a file using a private storage key.
     */
    public function store(
        string $sourcePath,
        string $storageKey,
        string $mimeType,
    ): void;

    /**
     * Opens a stored file for reading.
     *
     * @return resource|false
     */
    public function read(string $storageKey): mixed;

    public function delete(string $storageKey): void;

    public function exists(string $storageKey): bool;
}
