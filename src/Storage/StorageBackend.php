<?php

declare(strict_types=1);

namespace FileVault\Storage;

use FileVault\Storage\Exception\StorageException;

interface StorageBackend
{
    /**
     * @param int $projectId
     * @param resource $sourceStream
     * @return StoredObject
     * @throws StorageException
     */
    public function store(int $projectId, $sourceStream): StoredObject;

    /**
     * @param int $projectId
     * @param string $storageKey
     * @param bool $fromTrash
     * @return resource
     * @throws StorageException
     */
    public function openRead(int $projectId, string $storageKey, bool $fromTrash = false);

    /**
     * @param int $projectId
     * @param string $storageKey
     * @return void
     * @throws StorageException
     */
    public function moveToTrash(int $projectId, string $storageKey): void;

    /**
     * @param int $projectId
     * @param string $storageKey
     * @return void
     * @throws StorageException
     */
    public function restore(int $projectId, string $storageKey): void;

    /**
     * @param int $projectId
     * @param string $storageKey
     * @return void
     * @throws StorageException
     */
    public function purge(int $projectId, string $storageKey): void;
}
