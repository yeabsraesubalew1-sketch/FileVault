<?php

declare(strict_types=1);

namespace FileVault\Persistence\Record;

final readonly class FileRecord
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $originalName,
        public string $storageKey,
        public string $mimeType,
        public int $sizeBytes,
        public string $visibility,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $trashedAt,
    ) {}
}
