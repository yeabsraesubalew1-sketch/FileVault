<?php

declare(strict_types=1);

namespace FileVault\Persistence\Repository;

use FileVault\Persistence\Database;
use FileVault\Persistence\Record\ApiKeyRecord;
use FileVault\Persistence\Exception\QueryException;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class ApiKeyRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function create(
        int $projectId,
        string $keyHash,
        string $keyPrefix,
        string $status,
        \DateTimeImmutable $createdAt
    ): ApiKeyRecord {
        $sql = <<<SQL
            INSERT INTO api_keys (project_id, key_hash, key_prefix, status, created_at, last_used_at)
            VALUES (:project_id, :key_hash, :key_prefix, :status, :created_at, NULL)
        SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'project_id' => $projectId,
                'key_hash' => $keyHash,
                'key_prefix' => $keyPrefix,
                'status' => $status,
                'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            return $this->findById($id) ?? throw new QueryException('Failed to retrieve created API key.');
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConstraintViolationException('API key creation failed due to constraint violation.', 0, $e);
            }
            throw new QueryException('Failed to create API key.', 0, $e);
        }
    }

    public function findById(int $id): ?ApiKeyRecord
    {
        $sql = "SELECT * FROM api_keys WHERE id = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find API key by ID.', 0, $e);
        }
    }

    public function findByHash(string $keyHash): ?ApiKeyRecord
    {
        $sql = "SELECT * FROM api_keys WHERE key_hash = :key_hash";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['key_hash' => $keyHash]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find API key by hash.', 0, $e);
        }
    }

    /**
     * @return list<ApiKeyRecord>
     */
    public function listByProject(int $projectId): array
    {
        $sql = "SELECT * FROM api_keys WHERE project_id = :project_id ORDER BY id ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['project_id' => $projectId]);
            
            $records = [];
            while ($row = $stmt->fetch()) {
                $records[] = $this->mapToRecord($row);
            }
            
            return $records;
        } catch (\PDOException $e) {
            throw new QueryException('Failed to list API keys for project.', 0, $e);
        }
    }

    public function revoke(int $id): ApiKeyRecord
    {
        $sql = "UPDATE api_keys SET status = 'revoked' WHERE id = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            return $this->findById($id) ?? throw new QueryException('API key not found for revocation.');
        } catch (\PDOException $e) {
            throw new QueryException('Failed to revoke API key.', 0, $e);
        }
    }

    public function updateLastUsed(int $id, \DateTimeImmutable $usedAt): void
    {
        $sql = "UPDATE api_keys SET last_used_at = :last_used_at WHERE id = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'last_used_at' => $usedAt->format('Y-m-d H:i:s.u'),
            ]);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to update last used timestamp.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToRecord(array $row): ApiKeyRecord
    {
        return new ApiKeyRecord(
            id: (int) $row['id'],
            projectId: (int) $row['project_id'],
            keyHash: (string) $row['key_hash'],
            keyPrefix: (string) $row['key_prefix'],
            status: (string) $row['status'],
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            lastUsedAt: $row['last_used_at'] !== null 
                ? new \DateTimeImmutable((string) $row['last_used_at'], new \DateTimeZone('UTC')) 
                : null
        );
    }
}
