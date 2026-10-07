<?php

declare(strict_types=1);

namespace FileVault\Project;

use FileVault\Project\Exception\InvalidQuotaException;

final readonly class ProjectQuota
{
    public const VIOLATION_FILE_SIZE = 'file_size';
    public const VIOLATION_FILE_COUNT = 'file_count';
    public const VIOLATION_STORAGE = 'storage';

    public function __construct(
        public int $maxFileSize,
        public int $maxFileCount,
        public int $maxStorageBytes
    ) {
        if ($this->maxFileSize < 0) {
            throw new InvalidQuotaException('Max file size cannot be negative.');
        }
        if ($this->maxFileCount < 0) {
            throw new InvalidQuotaException('Max file count cannot be negative.');
        }
        if ($this->maxStorageBytes < 0) {
            throw new InvalidQuotaException('Max storage bytes cannot be negative.');
        }
    }

    public function isUnlimited(int $value): bool
    {
        return $value === 0;
    }

    public function fits(ProjectUsage $currentUsage, UsageDelta $delta, int $fileSize): QuotaCheckResult
    {
        if ($fileSize < 0) {
            throw new InvalidQuotaException('File size cannot be negative.');
        }

        $violations = [];

        if (!$this->isUnlimited($this->maxFileSize) && $fileSize > $this->maxFileSize) {
            $violations[] = self::VIOLATION_FILE_SIZE;
        }

        if ($delta->fileCountDelta > 0) {
            $newFileCount = $currentUsage->currentFileCount + $delta->fileCountDelta;
            if (!$this->isUnlimited($this->maxFileCount) && $newFileCount > $this->maxFileCount) {
                $violations[] = self::VIOLATION_FILE_COUNT;
            }
        }

        if ($delta->storageBytesDelta > 0) {
            $newStorageBytes = $currentUsage->currentStorageBytes + $delta->storageBytesDelta;
            if (!$this->isUnlimited($this->maxStorageBytes) && $newStorageBytes > $this->maxStorageBytes) {
                $violations[] = self::VIOLATION_STORAGE;
            }
        }

        return new QuotaCheckResult(empty($violations), $violations);
    }
}
