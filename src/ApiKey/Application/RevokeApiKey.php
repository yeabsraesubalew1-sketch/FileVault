<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Application;

use FileVault\ApiKey\ApiKey;
use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Exception\ApiKeyNotFoundException;
use FileVault\ApiKey\Persistence\ApiKeyRepository;

final class RevokeApiKey
{
    public function __construct(
        private ApiKeyRepository $repository
    ) {}

    public function revoke(ApiKeyId $publicId, \DateTimeImmutable $now): void
    {
        $record = $this->repository->findByPublicId($publicId);
        
        if ($record === null) {
            throw new ApiKeyNotFoundException('API key not found.');
        }

        if ($record->id === null) {
            throw new \FileVault\ApiKey\Exception\ApiKeyException('Persisted API key record must have an internal ID.');
        }

        $apiKey = new ApiKey(
            id: $record->id,
            publicId: $record->publicId,
            projectId: $record->projectId,
            name: $record->name,
            status: $record->status,
            createdAt: $record->createdAt,
            revokedAt: $record->revokedAt
        );

        $apiKey->revoke($now);

        /** @var \DateTimeImmutable $revokedAt */
        $revokedAt = $apiKey->getRevokedAt();

        // Pass internal ID to repository
        $this->repository->revoke($record->id, $revokedAt);
    }
}
