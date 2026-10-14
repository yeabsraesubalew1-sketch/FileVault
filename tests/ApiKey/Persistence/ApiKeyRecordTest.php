<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Persistence;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Exception\ApiKeyException;
use FileVault\ApiKey\Exception\InvalidApiKeyLifecycleOperationException;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use PHPUnit\Framework\TestCase;

class ApiKeyRecordTest extends TestCase
{
    private ApiKeyId $publicId;
    private ApiKeyName $name;
    private string $secretHash;

    protected function setUp(): void
    {
        $this->publicId = new ApiKeyId(random_bytes(16));
        $this->name = new ApiKeyName('Test Record Key');
        $this->secretHash = random_bytes(32);
    }

    public function testValidConstructionWithNullInternalId(): void
    {
        $createdAt = new \DateTimeImmutable();
        
        $record = new ApiKeyRecord(
            id: null,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt
        );

        $this->assertNull($record->id);
        $this->assertSame($this->publicId, $record->publicId);
        $this->assertSame(123, $record->projectId);
        $this->assertSame($this->name, $record->name);
        $this->assertSame($this->secretHash, $record->secretHash);
        $this->assertSame(ApiKeyStatus::ACTIVE, $record->status);
        $this->assertSame($createdAt, $record->createdAt);
        $this->assertNull($record->revokedAt);
    }

    public function testValidConstructionWithPositiveInternalId(): void
    {
        $createdAt = new \DateTimeImmutable();
        
        $record = new ApiKeyRecord(
            id: 42,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: $createdAt
        );

        $this->assertSame(42, $record->id);
    }

    public function testValidConstructionRevokedState(): void
    {
        $createdAt = new \DateTimeImmutable('-1 day');
        $revokedAt = new \DateTimeImmutable();

        $record = new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::REVOKED,
            createdAt: $createdAt,
            revokedAt: $revokedAt
        );

        $this->assertSame(ApiKeyStatus::REVOKED, $record->status);
        $this->assertSame($revokedAt, $record->revokedAt);
    }

    public function testInvalidInternalIdZero(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('Internal database ID must be a positive integer.');

        new ApiKeyRecord(
            id: 0,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testInvalidInternalIdNegative(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('Internal database ID must be a positive integer.');

        new ApiKeyRecord(
            id: -1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testInvalidProjectIdZero(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('Project ID must be a positive integer.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 0,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testInvalidProjectIdNegative(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('Project ID must be a positive integer.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: -5,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testHashShorterThan32BytesRejected(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('API key secret hash must be exactly 32 bytes.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: random_bytes(31),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testHashLongerThan32BytesRejected(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('API key secret hash must be exactly 32 bytes.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: random_bytes(33),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );
    }

    public function testActiveWithNonNullRevokedAtRejected(): void
    {
        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Active API key record cannot have a revocation timestamp.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable(),
            revokedAt: new \DateTimeImmutable()
        );
    }

    public function testRevokedWithNullRevokedAtRejected(): void
    {
        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Revoked API key record must have a revocation timestamp.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable(),
            revokedAt: null
        );
    }

    public function testRevokedAtBeforeCreatedAtRejected(): void
    {
        $this->expectException(InvalidApiKeyLifecycleOperationException::class);
        $this->expectExceptionMessage('Revocation timestamp cannot be before creation timestamp.');

        new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable('2023-01-02 12:00:00'),
            revokedAt: new \DateTimeImmutable('2023-01-01 12:00:00')
        );
    }

    public function testRecordIsImmutable(): void
    {
        $reflection = new \ReflectionClass(ApiKeyRecord::class);
        $this->assertTrue($reflection->isReadOnly());

        $record = new ApiKeyRecord(
            id: 1,
            publicId: $this->publicId,
            projectId: 123,
            name: $this->name,
            secretHash: $this->secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->expectException(\Error::class);
        
        // Dynamic access triggers runtime readonly Error without static analyzer/IDE complaints
        $property = 'id';
        $record->$property = 2;
    }
}
