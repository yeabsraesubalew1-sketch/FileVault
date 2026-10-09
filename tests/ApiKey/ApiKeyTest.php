<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey;

use FileVault\ApiKey\ApiKey;
use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Exception\InvalidApiKeyLifecycleOperationException;
use PHPUnit\Framework\TestCase;

class ApiKeyTest extends TestCase
{
    private ApiKeyId $publicId;
    private ApiKeyName $name;
    private int $projectId;

    protected function setUp(): void
    {
        $this->publicId = new ApiKeyId(random_bytes(16));
        $this->name = new ApiKeyName('Test Key');
        $this->projectId = 123;
    }

    public function testNewlyConstructedActiveKeyHasNoRevokedAt(): void
    {
        $createdAt = new \DateTimeImmutable();

        $key = new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt
        );

        $this->assertSame(ApiKeyStatus::ACTIVE, $key->getStatus());
        $this->assertNull($key->getRevokedAt());
    }

    public function testActiveKeyCanBeRevoked(): void
    {
        $createdAt = new \DateTimeImmutable('-1 day');
        $key = new ApiKey(
            id: 1,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt
        );

        $revokedAt = new \DateTimeImmutable();
        $key->revoke($revokedAt);

        $this->assertSame(ApiKeyStatus::REVOKED, $key->getStatus());
        $this->assertSame($revokedAt, $key->getRevokedAt());
    }

    public function testRevokingAlreadyRevokedKeyIsSuccessfulNoOp(): void
    {
        $createdAt = new \DateTimeImmutable('-2 days');
        $initialRevokedAt = new \DateTimeImmutable('-1 day');
        $key = new ApiKey(
            id: 1,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::REVOKED,
            createdAt: $createdAt,
            revokedAt: $initialRevokedAt
        );

        $newRevokedAt = new \DateTimeImmutable();
        $key->revoke($newRevokedAt);

        $this->assertSame(ApiKeyStatus::REVOKED, $key->getStatus());
        $this->assertSame($initialRevokedAt, $key->getRevokedAt());
    }

    public function testCannotCreateActiveKeyWithRevocationTimestamp(): void
    {
        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Active API key cannot have a revocation timestamp.');

        new ApiKey(
            id: 1,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable(),
            revokedAt: new \DateTimeImmutable()
        );
    }

    public function testCannotCreateRevokedKeyWithoutRevocationTimestamp(): void
    {
        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Revoked API key must have a revocation timestamp.');

        new ApiKey(
            id: 1,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable(),
            revokedAt: null
        );
    }

    public function testPropertiesAreExposedCorrectlyAndImmutable(): void
    {
        $createdAt = new \DateTimeImmutable();
        $key = new ApiKey(
            id: 10,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt
        );

        $this->assertSame(10, $key->id);
        $this->assertSame($this->publicId, $key->publicId);
        $this->assertSame($this->projectId, $key->projectId);
        $this->assertSame($this->name, $key->name);
        $this->assertSame($createdAt, $key->createdAt);
    }

    public function testProjectZeroIsRejected(): void
    {
        $this->expectException(\FileVault\ApiKey\Exception\ApiKeyException::class);
        $this->expectExceptionMessage('Project ID must be a positive integer.');

        new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: 0,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testNegativeProjectIdIsRejected(): void
    {
        $this->expectException(\FileVault\ApiKey\Exception\ApiKeyException::class);
        $this->expectExceptionMessage('Project ID must be a positive integer.');

        new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: -1,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testCannotCreateRevokedKeyWithRevokedAtBeforeCreatedAt(): void
    {
        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Revocation timestamp cannot be before creation timestamp.');

        $createdAt = new \DateTimeImmutable('2023-01-02 12:00:00');
        $revokedAt = new \DateTimeImmutable('2023-01-01 12:00:00');

        new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::REVOKED,
            createdAt: $createdAt,
            revokedAt: $revokedAt
        );
    }

    public function testRevokedAtEqualToCreatedAtIsAccepted(): void
    {
        $time = new \DateTimeImmutable();

        $key = new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::REVOKED,
            createdAt: $time,
            revokedAt: $time
        );

        $this->assertSame(ApiKeyStatus::REVOKED, $key->getStatus());
        $this->assertSame($time, $key->getRevokedAt());
    }

    public function testRevokedAtAfterCreatedAtIsAccepted(): void
    {
        $createdAt = new \DateTimeImmutable('2023-01-01 12:00:00');
        $revokedAt = new \DateTimeImmutable('2023-01-02 12:00:00');

        $key = new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::REVOKED,
            createdAt: $createdAt,
            revokedAt: $revokedAt
        );

        $this->assertSame(ApiKeyStatus::REVOKED, $key->getStatus());
        $this->assertSame($revokedAt, $key->getRevokedAt());
    }

    public function testCannotRevokeWithTimestampBeforeCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2023-01-02 12:00:00');
        $key = new ApiKey(
            id: null,
            publicId: $this->publicId,
            projectId: $this->projectId,
            name: $this->name,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt
        );

        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Cannot revoke with a timestamp before creation time.');

        $revokedAt = new \DateTimeImmutable('2023-01-01 12:00:00');
        $key->revoke($revokedAt);
    }
}
