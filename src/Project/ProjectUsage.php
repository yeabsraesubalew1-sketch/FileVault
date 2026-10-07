<?php

declare(strict_types=1);

namespace FileVault\Project;

use FileVault\Project\Exception\InvalidUsageException;

final readonly class ProjectUsage
{
    public function __construct(
        public int $currentFileCount,
        public int $currentStorageBytes
    ) {
        if ($this->currentFileCount < 0) {
            throw new InvalidUsageException('File count cannot be negative.');
        }
        
        if ($this->currentStorageBytes < 0) {
            throw new InvalidUsageException('Storage bytes cannot be negative.');
        }
    }
    
    public function applyDelta(UsageDelta $delta): self
    {
        return new self(
            $this->currentFileCount + $delta->fileCountDelta,
            $this->currentStorageBytes + $delta->storageBytesDelta
        );
    }
}
