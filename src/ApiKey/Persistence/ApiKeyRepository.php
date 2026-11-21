<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Persistence;

use FileVault\ApiKey\ApiKeyId;

interface ApiKeyRepository
{
    public function create(ApiKeyRecord $record): ApiKeyRecord;

    public function findById(int $id): ?ApiKeyRecord;

    public function findByPublicId(ApiKeyId $publicId): ?ApiKeyRecord;
    
    /**
     * @return list<ApiKeyRecord>
     */
    public function findByProject(int $projectId): array;

    public function revoke(int $id, \DateTimeImmutable $revokedAt): void;
}
