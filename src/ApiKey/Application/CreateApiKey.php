<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Application;

use FileVault\ApiKey\ApiKey;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Credential\ApiKeyGenerator;
use FileVault\ApiKey\Credential\ApiKeySecretHasher;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\ApiKey\Persistence\ApiKeyRepository;

final class CreateApiKey
{
    public function __construct(
        private ApiKeyGenerator $generator,
        private ApiKeySecretHasher $hasher,
        private ApiKeyRepository $repository
    ) {}

    public function create(int $projectId, string $name, \DateTimeImmutable $createdAt): CreatedApiKey
    {
        $apiKeyName = new ApiKeyName($name);
        
        $credentialResult = $this->generator->generate();
        
        $secretHash = $this->hasher->hash($credentialResult->rawSecretBytes);

        $record = new ApiKeyRecord(
            id: null,
            publicId: $credentialResult->publicId,
            projectId: $projectId,
            name: $apiKeyName,
            secretHash: $secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt,
            revokedAt: null
        );

        $persistedRecord = $this->repository->create($record);

        $persistedApiKey = new ApiKey(
            id: $persistedRecord->id,
            publicId: $persistedRecord->publicId,
            projectId: $persistedRecord->projectId,
            name: $persistedRecord->name,
            status: $persistedRecord->status,
            createdAt: $persistedRecord->createdAt,
            revokedAt: $persistedRecord->revokedAt
        );

        return new CreatedApiKey($persistedApiKey, $credentialResult->plaintextCredential);
    }
}
