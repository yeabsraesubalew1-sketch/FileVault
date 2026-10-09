<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Application;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Application\RevokeApiKey;
use FileVault\ApiKey\Exception\ApiKeyNotFoundException;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\ApiKey\Persistence\ApiKeyRepository;
use PHPUnit\Framework\TestCase;

class RevokeApiKeyTest extends TestCase
{
    public function testRevokeActiveKey(): void
    {
        $publicId = new ApiKeyId(random_bytes(16));
        $now = new \DateTimeImmutable();

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(new ApiKeyRecord(
            id: 123,
            publicId: $publicId,
            projectId: 1,
            name: new ApiKeyName('Test'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable('-1 day')
        ));

        $repository->expects($this->once())
            ->method('revoke')
            ->with($this->equalTo(123), $this->equalTo($now));

        $revokeAction = new RevokeApiKey($repository);
        $revokeAction->revoke($publicId, $now);
    }

    public function testRevokeUnknownKeyThrowsNotFoundException(): void
    {
        $publicId = new ApiKeyId(random_bytes(16));
        $now = new \DateTimeImmutable();

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(null);

        $revokeAction = new RevokeApiKey($repository);

        $this->expectException(ApiKeyNotFoundException::class);
        $revokeAction->revoke($publicId, $now);
    }

    public function testRevokeAlreadyRevokedKeyIsIdempotent(): void
    {
        $publicId = new ApiKeyId(random_bytes(16));
        $now = new \DateTimeImmutable();
        $originalRevokedAt = new \DateTimeImmutable('-1 hour');

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(new ApiKeyRecord(
            id: 123,
            publicId: $publicId,
            projectId: 1,
            name: new ApiKeyName('Test'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable('-1 day'),
            revokedAt: $originalRevokedAt
        ));

        // It must call revoke on repository but with the ORIGINAL revokedAt timestamp.
        $repository->expects($this->once())
            ->method('revoke')
            ->with($this->equalTo(123), $this->equalTo($originalRevokedAt));

        $revokeAction = new RevokeApiKey($repository);
        $revokeAction->revoke($publicId, $now);
    }

    public function testRevokeWithNullInternalIdThrowsException(): void
    {
        $publicId = new ApiKeyId(random_bytes(16));
        $now = new \DateTimeImmutable();

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(new ApiKeyRecord(
            id: null,
            publicId: $publicId,
            projectId: 1,
            name: new ApiKeyName('Test'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable('-1 day')
        ));

        $revokeAction = new RevokeApiKey($repository);

        $this->expectException(\FileVault\ApiKey\Exception\ApiKeyException::class);
        $this->expectExceptionMessage('Persisted API key record must have an internal ID.');
        
        $revokeAction->revoke($publicId, $now);
    }
}
