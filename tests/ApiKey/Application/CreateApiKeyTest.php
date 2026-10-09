<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Application;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Application\CreateApiKey;
use FileVault\ApiKey\Credential\ApiKeyGenerator;
use FileVault\ApiKey\Credential\ApiKeySecretHasher;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\ApiKey\Persistence\ApiKeyRepository;
use PHPUnit\Framework\TestCase;

class CreateApiKeyTest extends TestCase
{
    public function testCreateGeneratesValidKeyAndPassesToRepository(): void
    {
        $generator = new ApiKeyGenerator();
        $hasher = new ApiKeySecretHasher();
        
        $repository = $this->createMock(ApiKeyRepository::class);

        $now = new \DateTimeImmutable();
        
        $repository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (ApiKeyRecord $record) {
                // Assertions on the record passed to the repository
                $this->assertNull($record->id);
                $this->assertSame(123, $record->projectId);
                $this->assertSame('Test Key', $record->name->value);
                $this->assertSame(ApiKeyStatus::ACTIVE, $record->status);
                $this->assertNull($record->revokedAt);
                $this->assertSame(32, strlen($record->secretHash));
                
                // Return a "persisted" record with an ID
                return new ApiKeyRecord(
                    id: 42,
                    publicId: $record->publicId,
                    projectId: $record->projectId,
                    name: $record->name,
                    secretHash: $record->secretHash,
                    status: $record->status,
                    createdAt: $record->createdAt,
                    revokedAt: $record->revokedAt
                );
            });

        $createApiKey = new CreateApiKey($generator, $hasher, $repository);
        $result = $createApiKey->create(123, 'Test Key', $now);

        // Assertions on the result
        $this->assertSame(42, $result->apiKey->id);
        $this->assertSame(123, $result->apiKey->projectId);
        $this->assertSame('Test Key', $result->apiKey->name->value);
        $this->assertSame(ApiKeyStatus::ACTIVE, $result->apiKey->getStatus());
        $this->assertSame($now, $result->apiKey->createdAt);
        $this->assertNull($result->apiKey->getRevokedAt());

        // Credential is made available
        $this->assertStringStartsWith('fvk_', $result->plaintextCredential);
    }
}
