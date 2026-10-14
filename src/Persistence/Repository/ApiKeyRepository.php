<?php

declare(strict_types=1);

namespace FileVault\Persistence\Repository;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\ApiKey\Persistence\ApiKeyRepository as ApiKeyRepositoryInterface;
use FileVault\ApiKey\Exception\DuplicateApiKeyNameException;
use FileVault\Persistence\Database;
use FileVault\Persistence\Exception\QueryException;

final class ApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo) {}

    public function create(ApiKeyRecord $record): ApiKeyRecord
    {
        $sql = <<<SQL
            INSERT INTO api_keys (public_id, project_id, name, secret_hash, status, created_at, revoked_at)
            VALUES (:public_id, :project_id, :name, :secret_hash, :status, :created_at, :revoked_at)
        SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'public_id' => $record->publicId->bytes,
                'project_id' => $record->projectId,
                'name' => $record->name->value,
                'secret_hash' => $record->secretHash,
                'status' => $record->status->value,
                'created_at' => $record->createdAt->format('Y-m-d H:i:s.u'),
                'revoked_at' => $record->revokedAt?->format('Y-m-d H:i:s.u'),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            return $this->findById($id) ?? throw new QueryException('Failed to retrieve created API key.');
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                if (str_contains($e->getMessage(), 'uq_api_keys_project_name')) {
                    throw new DuplicateApiKeyNameException('API key name already exists in this project.', 0, $e);
                }
                throw new \FileVault\Persistence\Exception\ConstraintViolationException('API key creation failed due to constraint violation.', 0, $e);
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
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find API key by ID.', 0, $e);
        }
    }

    public function findByPublicId(ApiKeyId $publicId): ?ApiKeyRecord
    {
        $sql = "SELECT * FROM api_keys WHERE public_id = :public_id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['public_id' => $publicId->bytes]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find API key by public ID.', 0, $e);
        }
    }

    /**
     * @return list<ApiKeyRecord>
     */
    public function findByProject(int $projectId): array
    {
        $sql = "SELECT * FROM api_keys WHERE project_id = :project_id ORDER BY id ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['project_id' => $projectId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return array_map([$this, 'mapToRecord'], $rows);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to list API keys for project.', 0, $e);
        }
    }

    public function revoke(int $id, \DateTimeImmutable $revokedAt): void
    {
        $sql = "UPDATE api_keys SET status = :status, revoked_at = :revoked_at WHERE id = :id AND status = :active_status";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'status' => ApiKeyStatus::REVOKED->value,
                'active_status' => ApiKeyStatus::ACTIVE->value,
                'revoked_at' => $revokedAt->format('Y-m-d H:i:s.u'),
            ]);
            
            if ($stmt->rowCount() === 0) {
                // Determine if it was missing or already revoked
                $checkStmt = $this->pdo->prepare("SELECT 1 FROM api_keys WHERE id = :id");
                $checkStmt->execute(['id' => $id]);
                if (!$checkStmt->fetch()) {
                    throw new \FileVault\ApiKey\Exception\ApiKeyNotFoundException('API key not found for revocation.');
                }
            }
        } catch (\PDOException $e) {
            throw new QueryException('Failed to revoke API key.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToRecord(array $row): ApiKeyRecord
    {
        return new ApiKeyRecord(
            id: (int) $row['id'],
            publicId: new ApiKeyId((string) $row['public_id']),
            projectId: (int) $row['project_id'],
            name: new ApiKeyName((string) $row['name']),
            secretHash: (string) $row['secret_hash'],
            status: ApiKeyStatus::from((string) $row['status']),
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            revokedAt: $row['revoked_at'] !== null 
                ? new \DateTimeImmutable((string) $row['revoked_at'], new \DateTimeZone('UTC')) 
                : null
        );
    }
}
