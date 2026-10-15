<?php

declare(strict_types=1);

namespace FileVault\Storage\Local;

use FileVault\Foundation\Config\StorageConfig;
use FileVault\Storage\Exception\StorageConfigurationException;
use FileVault\Storage\Exception\StorageConflictException;
use FileVault\Storage\Exception\StorageObjectNotFoundException;
use FileVault\Storage\Exception\StorageOperationException;
use FileVault\Storage\StorageBackend;
use FileVault\Storage\StoredObject;

final class LocalFilesystemStorage implements StorageBackend
{
    private string $storageRoot;

    public function __construct(StorageConfig $config)
    {
        $this->storageRoot = rtrim($config->storageRoot, '/\\');
        
        if ($this->storageRoot === '') {
            throw new StorageConfigurationException('Storage root cannot be empty');
        }

        $isAbsolute = str_starts_with($this->storageRoot, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $this->storageRoot);
        if (!$isAbsolute) {
            throw new StorageConfigurationException('Storage root must be an absolute path');
        }
    }

    public function store(int $projectId, $sourceStream): StoredObject
    {
        $this->validateProjectId($projectId);
        
        if (!is_resource($sourceStream)) {
            throw new StorageOperationException('Source must be a resource');
        }

        $storageKey = $this->generateStorageKey();
        $finalPath = $this->getActivePath($projectId, $storageKey);
        
        $this->ensureDirectoryExists(dirname($finalPath));
        
        $tempPath = $this->getTempPath();
        $this->ensureDirectoryExists(dirname($tempPath));

        $tempStream = @fopen($tempPath, 'xb');
        if ($tempStream === false) {
            throw new StorageOperationException('Failed to create temporary file for storage');
        }

        if (!@chmod($tempPath, 0600)) {
            fclose($tempStream);
            @unlink($tempPath);
            throw new StorageOperationException('Failed to set correct permissions on temporary file');
        }

        try {
            $sizeBytes = @stream_copy_to_stream($sourceStream, $tempStream);
            if ($sizeBytes === false) {
                throw new StorageOperationException('Failed to stream data to temporary file');
            }
            
            if (!@fflush($tempStream)) {
                throw new StorageOperationException('Failed to flush temporary file');
            }
            
            if (!@fsync($tempStream)) {
                throw new StorageOperationException('Failed to fsync temporary file');
            }
        } catch (\Throwable $e) {
            fclose($tempStream);
            @unlink($tempPath);
            throw new StorageOperationException('Storage failed during write: ' . $e->getMessage(), 0, $e);
        }

        fclose($tempStream);

        // Physical relocation uses a same-filesystem link/unlink sequence that prevents destination overwrite. 
        // The sequence is not atomic; M06 must not report success when the source unlink phase fails.
        // For initial publication, a failure to unlink the temporary source is ignored since the object is successfully published.
        if (@link($tempPath, $finalPath)) {
            @unlink($tempPath);
        } else {
            @unlink($tempPath);
            if (file_exists($finalPath)) {
                throw new StorageConflictException('Storage conflict: object already exists at destination');
            }
            throw new StorageOperationException('Failed to atomically publish stored object');
        }

        return new StoredObject($storageKey, $sizeBytes);
    }

    public function openRead(int $projectId, string $storageKey, bool $fromTrash = false)
    {
        $this->validateProjectId($projectId);
        $this->validateStorageKey($storageKey);
        
        $path = $fromTrash
            ? $this->getTrashPath($projectId, $storageKey)
            : $this->getActivePath($projectId, $storageKey);
        
        $this->ensureValidFile($path);
        
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new StorageOperationException('Failed to open object for reading');
        }
        
        return $stream;
    }

    public function moveToTrash(int $projectId, string $storageKey): void
    {
        $this->validateProjectId($projectId);
        $this->validateStorageKey($storageKey);
        
        $activePath = $this->getActivePath($projectId, $storageKey);
        $trashPath = $this->getTrashPath($projectId, $storageKey);
        
        $this->ensureValidFile($activePath);
        $this->ensureDirectoryExists(dirname($trashPath));
        
        if (@link($activePath, $trashPath)) {
            if (!@unlink($activePath)) {
                throw new StorageOperationException('Failed to complete move to trash (unlink failed)');
            }
        } else {
            if (file_exists($trashPath)) {
                throw new StorageConflictException('Object already exists in trash');
            }
            throw new StorageOperationException('Failed to move object to trash');
        }
    }

    public function restore(int $projectId, string $storageKey): void
    {
        $this->validateProjectId($projectId);
        $this->validateStorageKey($storageKey);
        
        $activePath = $this->getActivePath($projectId, $storageKey);
        $trashPath = $this->getTrashPath($projectId, $storageKey);
        
        $this->ensureValidFile($trashPath);
        $this->ensureDirectoryExists(dirname($activePath));
        
        if (@link($trashPath, $activePath)) {
            if (!@unlink($trashPath)) {
                throw new StorageOperationException('Failed to complete restore (unlink failed)');
            }
        } else {
            if (file_exists($activePath)) {
                throw new StorageConflictException('Storage conflict: object already exists at destination');
            }
            $err = error_get_last();
            throw new StorageOperationException('Failed to restore object: ' . ($err['message'] ?? 'unknown error'));
        }
    }

    public function purge(int $projectId, string $storageKey): void
    {
        $this->validateProjectId($projectId);
        $this->validateStorageKey($storageKey);
        
        $trashPath = $this->getTrashPath($projectId, $storageKey);
        
        $this->ensureValidFile($trashPath);
        
        if (!@unlink($trashPath)) {
            throw new StorageOperationException('Failed to purge object');
        }
    }

    private function generateStorageKey(): string
    {
        $token = bin2hex(random_bytes(32));
        $date = gmdate('Y/m');
        return $date . '/' . $token;
    }

    private function validateProjectId(int $projectId): void
    {
        if ($projectId <= 0) {
            throw new StorageOperationException('Project ID must be a positive integer');
        }
    }

    private function validateStorageKey(string $storageKey): void
    {
        if (!preg_match('#^\d{4}/(0[1-9]|1[0-2])/[0-9a-f]{64}$#', $storageKey)) {
            throw new StorageOperationException('Invalid storage key format');
        }
    }

    private function getActivePath(int $projectId, string $storageKey): string
    {
        return sprintf('%s/projects/%d/active/%s', $this->storageRoot, $projectId, $storageKey);
    }

    private function getTrashPath(int $projectId, string $storageKey): string
    {
        return sprintf('%s/projects/%d/trash/%s', $this->storageRoot, $projectId, $storageKey);
    }

    private function getTempPath(): string
    {
        return sprintf('%s/.tmp/%s', $this->storageRoot, bin2hex(random_bytes(16)));
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new StorageOperationException('Failed to create storage directory');
            }
        }
    }

    private function ensureValidFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new StorageObjectNotFoundException('Object not found');
        }
        if (is_link($path)) {
            throw new StorageOperationException('Object is a symlink, which is not permitted');
        }
        if (!is_file($path)) {
            throw new StorageOperationException('Object is not a regular file');
        }
    }
}
