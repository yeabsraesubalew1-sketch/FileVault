<?php

declare(strict_types=1);

namespace FileVault\Project;

final readonly class UsageDelta
{
    public function __construct(
        public int $fileCountDelta,
        public int $storageBytesDelta
    ) {}
    
    public static function newFile(int $fileSize): self
    {
        return new self(1, $fileSize);
    }
    
    public static function trash(): self
    {
        return new self(0, 0);
    }
    
    public static function restore(): self
    {
        return new self(0, 0);
    }
    
    public static function replace(int $newFileSize): self
    {
        return new self(1, $newFileSize);
    }
    
    public static function purge(int $oldFileSize): self
    {
        return new self(-1, -$oldFileSize);
    }
}
