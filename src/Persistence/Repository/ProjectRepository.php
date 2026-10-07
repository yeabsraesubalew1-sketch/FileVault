<?php

declare(strict_types=1);

namespace FileVault\Persistence\Repository;

use FileVault\Persistence\Database;
use FileVault\Persistence\Record\ProjectRecord;
use FileVault\Project\Project;
use FileVault\Project\ProjectQuota;
use FileVault\Project\ProjectStatus;
use FileVault\Project\ProjectUsage;
use FileVault\Project\ProjectRepository as ProjectRepositoryInterface;
use FileVault\Persistence\Exception\QueryException;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class ProjectRepository implements ProjectRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo) {}

    public function findById(int $id): ?Project
    {
        $sql = "SELECT * FROM projects WHERE id = :id";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            
            if (!$row) {
                return null;
            }
            
            return $this->mapRecordToDomain($this->mapToRecord($row));
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find project by ID.', 0, $e);
        }
    }

    public function findByIdForUpdate(int $id): ?Project
    {
        $sql = "SELECT * FROM projects WHERE id = :id FOR UPDATE";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            
            if (!$row) {
                return null;
            }
            
            return $this->mapRecordToDomain($this->mapToRecord($row));
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find project by ID for update.', 0, $e);
        }
    }

    public function findByName(string $name): ?Project
    {
        $sql = "SELECT * FROM projects WHERE name = :name";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['name' => $name]);
            $row = $stmt->fetch();
            
            if (!$row) {
                return null;
            }
            
            return $this->mapRecordToDomain($this->mapToRecord($row));
        } catch (\PDOException $e) {
            throw new QueryException('Failed to find project by name.', 0, $e);
        }
    }

    public function save(Project $project): Project
    {
        if ($project->id === 0) {
            return $this->insert($project);
        }
        
        return $this->update($project);
    }

    private function insert(Project $project): Project
    {
        $record = $this->mapDomainToRecord($project);
        
        $sql = <<<SQL
            INSERT INTO projects (
                name, status, max_file_size, max_file_count, max_storage_bytes,
                current_file_count, current_storage_bytes, created_at, updated_at
            ) VALUES (
                :name, :status, :max_file_size, :max_file_count, :max_storage_bytes,
                :current_file_count, :current_storage_bytes, :created_at, :updated_at
            )
        SQL;
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'name' => $record->name,
                'status' => $record->status,
                'max_file_size' => $record->maxFileSize,
                'max_file_count' => $record->maxFileCount,
                'max_storage_bytes' => $record->maxStorageBytes,
                'current_file_count' => $record->currentFileCount,
                'current_storage_bytes' => $record->currentStorageBytes,
                'created_at' => $record->createdAt->format('Y-m-d H:i:s.u'),
                'updated_at' => $record->updatedAt->format('Y-m-d H:i:s.u'),
            ]);
            
            $id = (int) $this->pdo->lastInsertId();
            return $this->cloneProjectWithId($project, $id);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConstraintViolationException('Project creation failed due to constraint violation.', 0, $e);
            }
            throw new QueryException('Failed to create project.', 0, $e);
        }
    }

    private function update(Project $project): Project
    {
        $record = $this->mapDomainToRecord($project);
        
        $sql = <<<SQL
            UPDATE projects
            SET name = :name,
                status = :status,
                max_file_size = :max_file_size,
                max_file_count = :max_file_count,
                max_storage_bytes = :max_storage_bytes,
                current_file_count = :current_file_count,
                current_storage_bytes = :current_storage_bytes,
                updated_at = :updated_at
            WHERE id = :id
        SQL;
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'name' => $record->name,
                'status' => $record->status,
                'max_file_size' => $record->maxFileSize,
                'max_file_count' => $record->maxFileCount,
                'max_storage_bytes' => $record->maxStorageBytes,
                'current_file_count' => $record->currentFileCount,
                'current_storage_bytes' => $record->currentStorageBytes,
                'updated_at' => $record->updatedAt->format('Y-m-d H:i:s.u'),
                'id' => $record->id,
            ]);
            
            return $project;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ConstraintViolationException('Project update failed due to constraint violation.', 0, $e);
            }
            throw new QueryException('Failed to update project settings.', 0, $e);
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
            status: (string) $row['status'],
            maxFileSize: (int) $row['max_file_size'],
            maxFileCount: (int) $row['max_file_count'],
            maxStorageBytes: (int) $row['max_storage_bytes'],
            currentFileCount: (int) $row['current_file_count'],
            currentStorageBytes: (int) $row['current_storage_bytes'],
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC'))
        );
    }

    private function mapRecordToDomain(ProjectRecord $record): Project
    {
        return new Project(
            $record->id,
            $record->name,
            ProjectStatus::from($record->status),
            new ProjectQuota($record->maxFileSize, $record->maxFileCount, $record->maxStorageBytes),
            new ProjectUsage($record->currentFileCount, $record->currentStorageBytes),
            $record->createdAt,
            $record->updatedAt
        );
    }

    private function mapDomainToRecord(Project $project): ProjectRecord
    {
        return new ProjectRecord(
            id: $project->id,
            name: $project->name,
            status: $project->getStatus()->value,
            maxFileSize: $project->getQuota()->maxFileSize,
            maxFileCount: $project->getQuota()->maxFileCount,
            maxStorageBytes: $project->getQuota()->maxStorageBytes,
            currentFileCount: $project->getUsage()->currentFileCount,
            currentStorageBytes: $project->getUsage()->currentStorageBytes,
            createdAt: $project->createdAt,
            updatedAt: $project->getUpdatedAt()
        );
    }

    private function cloneProjectWithId(Project $project, int $id): Project
    {
        return new Project(
            $id,
            $project->name,
            $project->getStatus(),
            $project->getQuota(),
            $project->getUsage(),
            $project->createdAt,
            $project->getUpdatedAt()
        );
    }
}
