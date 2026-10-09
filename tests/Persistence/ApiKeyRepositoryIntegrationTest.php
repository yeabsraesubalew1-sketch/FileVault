<?php

declare(strict_types=1);

namespace FileVault\Tests\Persistence;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Exception\DuplicateApiKeyNameException;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\Persistence\Repository\ApiKeyRepository;
use FileVault\Persistence\Repository\ProjectRepository;
use FileVault\Persistence\Exception\QueryException;

class ApiKeyRepositoryIntegrationTest extends IntegrationTestCase
{
    private ApiKeyRepository $apiKeyRepository;
    private ProjectRepository $projectRepository;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);

        $this->apiKeyRepository = new ApiKeyRepository($pdo);
        $this->projectRepository = new ProjectRepository($pdo);

        // Create a test project
        $now = new \DateTimeImmutable();
        $project = new \FileVault\Project\Project(
            0,
            'Test Project',
            \FileVault\Project\ProjectStatus::ACTIVE,
            new \FileVault\Project\ProjectQuota(1024, 10, 10240),
            new \FileVault\Project\ProjectUsage(0, 0),
            $now,
            $now
        );
        $savedProject = $this->projectRepository->save($project);
        $this->projectId = $savedProject->id;
    }

    public function testMigrationCreatesSchemaAndRecordCreationPopulatesId(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('My Key'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable('2023-01-01 12:00:00', new \DateTimeZone('UTC'))
        );

        $created = $this->apiKeyRepository->create($record);

        $this->assertIsInt($created->id);
        $this->assertGreaterThan(0, $created->id);
        $this->assertEquals($record->publicId->bytes, $created->publicId->bytes);
        $this->assertEquals($record->name->value, $created->name->value);
        $this->assertEquals($record->secretHash, $created->secretHash);
        $this->assertEquals($record->status, $created->status);
        $this->assertEquals($record->createdAt->format('Y-m-d H:i:s.u'), $created->createdAt->format('Y-m-d H:i:s.u'));
        $this->assertNull($created->revokedAt);
    }

    public function testBothLookupsReturnCorrectRecordsAndRoundTripBinary(): void
    {
        $publicIdBytes = random_bytes(16);
        $secretHashBytes = random_bytes(32);

        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId($publicIdBytes),
            projectId: $this->projectId,
            name: new ApiKeyName('Lookup Key'),
            secretHash: $secretHashBytes,
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable('2023-01-01 12:00:00', new \DateTimeZone('UTC')),
            revokedAt: new \DateTimeImmutable('2023-01-02 12:00:00', new \DateTimeZone('UTC'))
        );

        $created = $this->apiKeyRepository->create($record);

        $findById = $this->apiKeyRepository->findById($created->id);
        $this->assertNotNull($findById);
        $this->assertSame($created->id, $findById->id);
        $this->assertSame($publicIdBytes, $findById->publicId->bytes);
        $this->assertSame($secretHashBytes, $findById->secretHash);
        $this->assertSame(ApiKeyStatus::REVOKED, $findById->status);

        $findByPublicId = $this->apiKeyRepository->findByPublicId(new ApiKeyId($publicIdBytes));
        $this->assertNotNull($findByPublicId);
        $this->assertSame($created->id, $findByPublicId->id);
        $this->assertSame($publicIdBytes, $findByPublicId->publicId->bytes);
    }

    public function testMissingLookupsReturnNull(): void
    {
        $this->assertNull($this->apiKeyRepository->findById(999999));
        $this->assertNull($this->apiKeyRepository->findByPublicId(new ApiKeyId(random_bytes(16))));
    }

    public function testDuplicatePublicIdsAreRejectedByDatabase(): void
    {
        $publicId = new ApiKeyId(random_bytes(16));
        $record = new ApiKeyRecord(
            id: null,
            publicId: $publicId,
            projectId: $this->projectId,
            name: new ApiKeyName('Key 1'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->apiKeyRepository->create($record);

        $record2 = new ApiKeyRecord(
            id: null,
            publicId: $publicId,
            projectId: $this->projectId,
            name: new ApiKeyName('Key 2'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->expectException(\FileVault\Persistence\Exception\ConstraintViolationException::class);
        $this->apiKeyRepository->create($record2);
    }

    public function testDuplicateNamesInSameProjectRejectedCaseInsensitively(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('Duplicate Key'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->apiKeyRepository->create($record);

        $record2 = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('duPlicate KEY'), // MySQL handles utf8mb4_unicode_ci for varchar case-insensitive uniqueness
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->expectException(DuplicateApiKeyNameException::class);
        $this->apiKeyRepository->create($record2);
    }

    public function testIdenticalNamesInDifferentProjectsAreAllowed(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('Shared Name'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->apiKeyRepository->create($record);

        $now = new \DateTimeImmutable();
        $project2 = new \FileVault\Project\Project(
            0,
            'Project 2',
            \FileVault\Project\ProjectStatus::ACTIVE,
            new \FileVault\Project\ProjectQuota(1024, 10, 10240),
            new \FileVault\Project\ProjectUsage(0, 0),
            $now,
            $now
        );
        $savedProject2 = $this->projectRepository->save($project2);
        $projectId2 = $savedProject2->id;

        $record2 = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $projectId2,
            name: new ApiKeyName('Shared Name'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $created2 = $this->apiKeyRepository->create($record2);
        $this->assertNotNull($created2->id);
    }

    public function testNonexistentProjectIdIsRejected(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: 999999, // Does not exist
            name: new ApiKeyName('Key'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->expectException(\FileVault\Persistence\Exception\ConstraintViolationException::class);
        $this->apiKeyRepository->create($record);
    }

    public function testProjectDeletionIsRestricted(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('Key'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $this->apiKeyRepository->create($record);
        
        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);

        $this->expectException(\FileVault\Persistence\Exception\ConstraintViolationException::class);
        
        try {
            $pdo->exec("DELETE FROM projects WHERE id = {$this->projectId}");
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \FileVault\Persistence\Exception\ConstraintViolationException('Constraint violation', 0, $e);
            }
            throw $e;
        }
    }

    public function testActiveKeyRevocationPersistsStatusAndTimestamp(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('Key to Revoke'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        );

        $created = $this->apiKeyRepository->create($record);

        $now = new \DateTimeImmutable();
        $this->apiKeyRepository->revoke($created->id, $now);

        $updated = $this->apiKeyRepository->findById($created->id);
        $this->assertSame(ApiKeyStatus::REVOKED, $updated->status);
        $this->assertSame($now->format('Y-m-d H:i:s.u'), $updated->revokedAt->format('Y-m-d H:i:s.u'));
    }

    public function testRepeatedRevocationPreservesOriginalTimestamp(): void
    {
        $record = new ApiKeyRecord(
            id: null,
            publicId: new ApiKeyId(random_bytes(16)),
            projectId: $this->projectId,
            name: new ApiKeyName('Already Revoked Key'),
            secretHash: random_bytes(32),
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable('-2 hours'),
            revokedAt: new \DateTimeImmutable('-1 hour')
        );

        $created = $this->apiKeyRepository->create($record);

        $now = new \DateTimeImmutable();
        $this->apiKeyRepository->revoke($created->id, $now); // Attempt to revoke again

        $updated = $this->apiKeyRepository->findById($created->id);
        $this->assertSame(ApiKeyStatus::REVOKED, $updated->status);
        $this->assertSame($record->revokedAt->format('Y-m-d H:i:s.u'), $updated->revokedAt->format('Y-m-d H:i:s.u'));
    }

    public function testMissingKeyRevocationFollowsContract(): void
    {
        $this->expectException(\FileVault\ApiKey\Exception\ApiKeyNotFoundException::class);
        $this->apiKeyRepository->revoke(999999, new \DateTimeImmutable());
    }

    public function testInvalidStatusTimestampCombinationsAreRejectedByDatabase(): void
    {
        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);
        
        $insertSql = <<<SQL
            INSERT INTO api_keys (public_id, project_id, name, secret_hash, status, created_at, revoked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL;

        // 1. Active but with revokedAt set
        try {
            $pdo->prepare($insertSql)->execute([
                random_bytes(16), $this->projectId, 'Key 1', random_bytes(32), 'active', '2023-01-01 12:00:00', '2023-01-02 12:00:00'
            ]);
            $this->fail('Database did not reject active status with a revocation timestamp.');
        } catch (\PDOException $e) {
            $this->assertSame('HY000', $e->getCode()); // CHECK constraint failure often maps to HY000 or similar
        }
    }

    public function testRevokedWithoutTimestampIsRejectedByDatabase(): void
    {
        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);
        
        $insertSql = <<<SQL
            INSERT INTO api_keys (public_id, project_id, name, secret_hash, status, created_at, revoked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL;

        // 2. Revoked but missing revokedAt
        try {
            $pdo->prepare($insertSql)->execute([
                random_bytes(16), $this->projectId, 'Key 2', random_bytes(32), 'revoked', '2023-01-01 12:00:00', null
            ]);
            $this->fail('Database did not reject revoked status without a revocation timestamp.');
        } catch (\PDOException $e) {
            $this->assertSame('HY000', $e->getCode());
        }
    }

    public function testRevokedBeforeCreatedIsRejectedByDatabase(): void
    {
        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);
        
        $insertSql = <<<SQL
            INSERT INTO api_keys (public_id, project_id, name, secret_hash, status, created_at, revoked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL;

        // 3. Revoked before created
        try {
            $pdo->prepare($insertSql)->execute([
                random_bytes(16), $this->projectId, 'Key 3', random_bytes(32), 'revoked', '2023-01-02 12:00:00', '2023-01-01 12:00:00'
            ]);
            $this->fail('Database did not reject revocation timestamp preceding creation timestamp.');
        } catch (\PDOException $e) {
            $this->assertSame('HY000', $e->getCode());
        }
    }
}
