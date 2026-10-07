<?php

declare(strict_types=1);

namespace FileVault\Persistence\Record;

final readonly class ApiKeyRecord
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $keyHash,
        public string $keyPrefix,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt,
    ) {}
}
