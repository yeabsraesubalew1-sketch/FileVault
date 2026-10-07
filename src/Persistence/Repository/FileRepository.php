<?php

declare(strict_types=1);

namespace FileVault\Persistence\Repository;

use FileVault\Persistence\Database;
use FileVault\Persistence\Record\FileRecord;
use FileVault\Persistence\Exception\QueryException;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class FileRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function create(
        int $projectId,
        string $originalName,
        string $storageKey,
        string $mimeType,
        int $sizeBytes,
        string $visibility,
        string $status,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt
    ): FileRecord {
        $sql = <<<SQL
            INSERT INTO files (
                project_id, original_name, storage_key, mime_type, size_bytes, visibility, status, created_at, updated_at, trashed_at
            ) VALUES (
                :project_id, :original_name, :storage_key, :mime_type, :size_bytes, :visibility, :status, :created_at, :updated_at, NULL
            )
        SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'project_id' => $projectId,
                'original_name' => $originalName,
                'storage_key' => $storageKey,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'visibility' => $visibility,
                'status' => $status,
                'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
                'updated_at' => $updatedAt->format('Y-m-d H:i:s.u'),
            ]);

            $id = (int) $this->pdo->lastInsertId();
            return $this->findById($id) ?? throw new QueryException('Failed to retrieve created file.');
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConstraintViolationException('File creation failed due to constraint violation.', 0, $e);
            }
            throw new QueryException('Failed to create file.', 0, $e);
        }
    }

    public function findById(int $id): ?FileRecord
    {
        $sql = "SELECT * FROM files WHERE id = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find file by ID.', 0, $e);
        }
    }

    public function findByProjectAndId(int $projectId, int $fileId): ?FileRecord
    {
        $sql = "SELECT * FROM files WHERE id = :id AND project_id = :project_id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'id' => $fileId,
                'project_id' => $projectId,
            ]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find file by project and ID.', 0, $e);
        }
    }

    public function markTrashed(int $projectId, int $fileId, \DateTimeImmutable $trashedAt): FileRecord
    {
        $sql = <<<SQL
            UPDATE files
            SET status = 'trashed',
                trashed_at = :trashed_at,
                updated_at = :updated_at
            WHERE id = :id
              AND project_id = :project_id
              AND status = 'active'
        SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'trashed_at' => $trashedAt->format('Y-m-d H:i:s.u'),
                'updated_at' => $trashedAt->format('Y-m-d H:i:s.u'),
                'id' => $fileId,
                'project_id' => $projectId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new QueryException('File not found, not in project, or already trashed.');
            }

            return $this->findByProjectAndId($projectId, $fileId) ?? throw new QueryException('Failed to retrieve trashed file.');
        } catch (\PDOException $e) {
            throw new QueryException('Failed to mark file as trashed.', 0, $e);
        }
    }

    public function restore(int $projectId, int $fileId, \DateTimeImmutable $updatedAt): FileRecord
    {
        $sql = <<<SQL
            UPDATE files
            SET status = 'active',
                trashed_at = NULL,
                updated_at = :updated_at
            WHERE id = :id
              AND project_id = :project_id
              AND status = 'trashed'
        SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'updated_at' => $updatedAt->format('Y-m-d H:i:s.u'),
                'id' => $fileId,
                'project_id' => $projectId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new QueryException('File not found, not in project, or not trashed.');
            }

            return $this->findByProjectAndId($projectId, $fileId) ?? throw new QueryException('Failed to retrieve restored file.');
        } catch (\PDOException $e) {
            throw new QueryException('Failed to restore file.', 0, $e);
        }
    }

    public function purge(int $projectId, int $fileId): void
    {
        $sql = "DELETE FROM files WHERE id = :id AND project_id = :project_id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'id' => $fileId,
                'project_id' => $projectId,
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new QueryException('File not found or not in project to purge.');
            }
        } catch (\PDOException $e) {
            throw new QueryException('Failed to purge file.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToRecord(array $row): FileRecord
    {
        return new FileRecord(
            id: (int) $row['id'],
            projectId: (int) $row['project_id'],
            originalName: (string) $row['original_name'],
            storageKey: (string) $row['storage_key'],
            mimeType: (string) $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            visibility: (string) $row['visibility'],
            status: (string) $row['status'],
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC')),
            trashedAt: $row['trashed_at'] !== null 
                ? new \DateTimeImmutable((string) $row['trashed_at'], new \DateTimeZone('UTC')) 
                : null
        );
    }
}
