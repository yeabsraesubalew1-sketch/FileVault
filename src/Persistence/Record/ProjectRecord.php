<?php

declare(strict_types=1);

namespace FileVault\Persistence\Record;

final readonly class ProjectRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public int $maxFileSize,
        public int $maxFileCount,
        public int $maxStorageBytes,
        public int $currentFileCount,
        public int $currentStorageBytes,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
