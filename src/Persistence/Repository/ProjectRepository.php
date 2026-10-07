<?php

declare(strict_types=1);

namespace FileVault\Persistence\Repository;

use FileVault\Persistence\Database;
use FileVault\Persistence\Record\ProjectRecord;
use FileVault\Persistence\Exception\QueryException;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class ProjectRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function create(
        string $name,
        string $slug,
        string $status,
        int $storageQuota,
        int $maxFileSize,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt
    ): ProjectRecord {
        $sql = <<<SQL
            INSERT INTO projects (name, slug, status, storage_quota, max_file_size, created_at, updated_at)
            VALUES (:name, :slug, :status, :storage_quota, :max_file_size, :created_at, :updated_at)
        SQL;
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'status' => $status,
                'storage_quota' => $storageQuota,
                'max_file_size' => $maxFileSize,
                'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
                'updated_at' => $updatedAt->format('Y-m-d H:i:s.u'),
            ]);
            
            $id = (int) $this->pdo->lastInsertId();
            return $this->findById($id) ?? throw new QueryException('Failed to retrieve created project.');
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConstraintViolationException('Project creation failed due to constraint violation.', 0, $e);
            }
            throw new QueryException('Failed to create project.', 0, $e);
        }
    }

    public function findById(int $id): ?ProjectRecord
    {
        $sql = "SELECT * FROM projects WHERE id = :id";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            
            if (!$row) {
                return null;
            }
            
            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find project by ID.', 0, $e);
        }
    }

    public function findBySlug(string $slug): ?ProjectRecord
    {
        $sql = "SELECT * FROM projects WHERE slug = :slug";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch();
            
            if (!$row) {
                return null;
            }
            
            return $this->mapToRecord($row);
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find project by slug.', 0, $e);
        }
    }

    public function updateProjectSettings(
        int $id,
        string $name,
        string $slug,
        int $storageQuota,
        int $maxFileSize,
        \DateTimeImmutable $updatedAt
    ): ProjectRecord {
        $sql = <<<SQL
            UPDATE projects
            SET name = :name,
                slug = :slug,
                storage_quota = :storage_quota,
                max_file_size = :max_file_size,
                updated_at = :updated_at
            WHERE id = :id
        SQL;
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'storage_quota' => $storageQuota,
                'max_file_size' => $maxFileSize,
                'updated_at' => $updatedAt->format('Y-m-d H:i:s.u'),
                'id' => $id,
            ]);
            
            if ($stmt->rowCount() === 0) {
                throw new QueryException('Project not found or no changes made.'); // Ideally we check if it exists first or just return findById. Wait, spec says updateProjectSettings: ProjectRecord
            }
            
            return $this->findById($id) ?? throw new QueryException('Failed to retrieve updated project.');
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConstraintViolationException('Project update failed due to constraint violation.', 0, $e);
            }
            throw new QueryException('Failed to update project settings.', 0, $e);
        }
    }

    public function updateStatus(
        int $id,
        string $status,
        \DateTimeImmutable $updatedAt
    ): ProjectRecord {
        $sql = <<<SQL
            UPDATE projects
            SET status = :status,
                updated_at = :updated_at
            WHERE id = :id
        SQL;
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'status' => $status,
                'updated_at' => $updatedAt->format('Y-m-d H:i:s.u'),
                'id' => $id,
            ]);
            
            if ($stmt->rowCount() === 0) {
                 // The project might not exist. We just rely on findById below to throw or return null. Actually contract requires returning ProjectRecord.
            }
            
            return $this->findById($id) ?? throw new QueryException('Project not found for status update.');
        } catch (\PDOException $e) {
            throw new QueryException('Failed to update project status.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToRecord(array $row): ProjectRecord
    {
        return new ProjectRecord(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            status: (string) $row['status'],
            storageQuota: (int) $row['storage_quota'],
            maxFileSize: (int) $row['max_file_size'],
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC'))
        );
    }
}
