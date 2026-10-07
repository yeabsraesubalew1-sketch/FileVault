<?php

declare(strict_types=1);

namespace FileVault\Lifecycle;

use FileVault\Persistence\Database;
use FileVault\Persistence\Repository\FileRepository;
use FileVault\Storage\StorageBackend;
use FileVault\Persistence\Record\FileRecord;
use FileVault\Lifecycle\Exception\LifecycleException;
use FileVault\Lifecycle\Exception\InvalidLifecycleOperationException;
use FileVault\Lifecycle\Exception\LifecycleConsistencyException;
use FileVault\Storage\Exception\StorageObjectNotFoundException;

class FileLifecycle
{
    private FileRepository $fileRepository;

    public function __construct(
        private readonly Database $database,
        private readonly StorageBackend $storageBackend
    ) {
        $this->fileRepository = $this->database->createFileRepository();
    }

    /**
     * @param int $projectId
     * @param resource $sourceStream
     * @param string $originalName
     * @param string $mimeType
     * @param string $visibility
     * @return FileRecord
     * @throws LifecycleException
     */
    public function create(
        int $projectId,
        $sourceStream,
        string $originalName,
        string $mimeType,
        string $visibility
    ): FileRecord {
        try {
            $storedObject = $this->storageBackend->store($projectId, $sourceStream);
        } catch (\Throwable $e) {
            throw new LifecycleException('Physical storage failed during create.', 0, $e);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            return $this->fileRepository->create(
                $projectId,
                $originalName,
                $storedObject->storageKey,
                $mimeType,
                $storedObject->sizeBytes,
                $visibility,
                'active',
                $now,
                $now
            );
        } catch (\Throwable $e) {
            try {
                $this->storageBackend->moveToTrash($projectId, $storedObject->storageKey);
                $this->storageBackend->purge($projectId, $storedObject->storageKey);
            } catch (\Throwable $compEx) {
                throw new LifecycleConsistencyException(
                    'Database creation failed and physical compensation failed. Orphaned physical file.',
                    $e,
                    $compEx
                );
            }
            throw new LifecycleException('Database creation failed. Physical storage was compensated.', 0, $e);
        }
    }

    public function trash(int $projectId, int $fileId): void
    {
        $file = $this->fileRepository->findByProjectAndId($projectId, $fileId);
        if (!$file) {
            throw new InvalidLifecycleOperationException('File not found or not in project.');
        }

        if ($file->status !== 'active') {
            throw new InvalidLifecycleOperationException('Only active files can be trashed.');
        }

        try {
            $this->storageBackend->moveToTrash($projectId, $file->storageKey);
        } catch (\Throwable $e) {
            throw new LifecycleException('Physical move to trash failed.', 0, $e);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $this->fileRepository->markTrashed($projectId, $fileId, $now);
        } catch (\Throwable $e) {
            try {
                $this->storageBackend->restore($projectId, $file->storageKey);
            } catch (\Throwable $compEx) {
                throw new LifecycleConsistencyException('Database trash failed and physical restore compensation failed.', $e, $compEx);
            }
            throw new LifecycleException('Database trash failed. Physical storage was compensated.', 0, $e);
        }
    }

    public function restore(int $projectId, int $fileId): void
    {
        $file = $this->fileRepository->findByProjectAndId($projectId, $fileId);
        if (!$file) {
            throw new InvalidLifecycleOperationException('File not found or not in project.');
        }

        if ($file->status !== 'trashed') {
            throw new InvalidLifecycleOperationException('Only trashed files can be restored.');
        }

        try {
            $this->storageBackend->restore($projectId, $file->storageKey);
        } catch (\Throwable $e) {
            throw new LifecycleException('Physical restore failed.', 0, $e);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $this->fileRepository->restore($projectId, $fileId, $now);
        } catch (\Throwable $e) {
            try {
                $this->storageBackend->moveToTrash($projectId, $file->storageKey);
            } catch (\Throwable $compEx) {
                throw new LifecycleConsistencyException('Database restore failed and physical trash compensation failed.', $e, $compEx);
            }
            throw new LifecycleException('Database restore failed. Physical storage was compensated.', 0, $e);
        }
    }

    public function purge(int $projectId, int $fileId): void
    {
        $file = $this->fileRepository->findByProjectAndId($projectId, $fileId);
        if (!$file) {
            throw new InvalidLifecycleOperationException('File not found or not in project.');
        }

        if ($file->status !== 'trashed') {
            throw new InvalidLifecycleOperationException('Only trashed files can be purged.');
        }

        try {
            $this->storageBackend->purge($projectId, $file->storageKey);
        } catch (StorageObjectNotFoundException $e) {
            // M06 correctly reports missing physical objects.
            // Proceed to M05 purge as physical deletion is already satisfied.
        } catch (\Throwable $e) {
            throw new LifecycleException('Physical purge failed.', 0, $e);
        }

        try {
            $this->fileRepository->purge($projectId, $fileId);
        } catch (\Throwable $e) {
            throw new LifecycleException('Database purge failed after physical deletion.', 0, $e);
        }
    }

    /**
     * @param int $projectId
     * @param int $fileId
     * @param resource $sourceStream
     * @param string $newOriginalName
     * @param string $newMimeType
     * @param string $newVisibility
     * @return FileRecord
     * @throws LifecycleException
     */
    public function replace(
        int $projectId,
        int $fileId,
        $sourceStream,
        string $newOriginalName,
        string $newMimeType,
        string $newVisibility
    ): FileRecord {
        $oldFile = $this->fileRepository->findByProjectAndId($projectId, $fileId);
        if (!$oldFile) {
            throw new InvalidLifecycleOperationException('File not found or not in project.');
        }

        if ($oldFile->status !== 'active') {
            throw new InvalidLifecycleOperationException('Only active files can be replaced.');
        }

        try {
            $newStoredObject = $this->storageBackend->store($projectId, $sourceStream);
        } catch (\Throwable $e) {
            throw new LifecycleException('Physical storage of new file failed during replacement.', 0, $e);
        }

        try {
            $this->storageBackend->moveToTrash($projectId, $oldFile->storageKey);
        } catch (\Throwable $e) {
            try {
                $this->storageBackend->moveToTrash($projectId, $newStoredObject->storageKey);
                $this->storageBackend->purge($projectId, $newStoredObject->storageKey);
            } catch (\Throwable $compEx) {
                throw new LifecycleConsistencyException('Physical trash of old file failed, and compensation of new file failed.', $e, $compEx);
            }
            throw new LifecycleException('Physical trash of old file failed. New file storage was compensated.', 0, $e);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            return $this->database->transaction(function () use (
                $projectId, $fileId, $newStoredObject, $newOriginalName, $newMimeType, $newVisibility, $now
            ) {
                $newRecord = $this->fileRepository->create(
                    $projectId,
                    $newOriginalName,
                    $newStoredObject->storageKey,
                    $newMimeType,
                    $newStoredObject->sizeBytes,
                    $newVisibility,
                    'active',
                    $now,
                    $now
                );

                $this->fileRepository->markTrashed($projectId, $fileId, $now);

                return $newRecord;
            });
        } catch (\Throwable $e) {
            try {
                $this->storageBackend->restore($projectId, $oldFile->storageKey);
                $this->storageBackend->moveToTrash($projectId, $newStoredObject->storageKey);
                $this->storageBackend->purge($projectId, $newStoredObject->storageKey);
            } catch (\Throwable $compEx) {
                throw new LifecycleConsistencyException('Database transaction failed, and physical compensation failed.', $e, $compEx);
            }
            throw new LifecycleException('Database transaction failed. Physical state was fully compensated.', 0, $e);
        }
    }
}
