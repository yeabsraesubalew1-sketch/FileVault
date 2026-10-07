<?php

declare(strict_types=1);

namespace FileVault\Persistence\Record;

final readonly class ProjectRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $status,
        public int $storageQuota,
        public int $maxFileSize,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
