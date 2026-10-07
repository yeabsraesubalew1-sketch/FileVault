<?php

declare(strict_types=1);

namespace FileVault\Persistence;

use FileVault\Foundation\Config\Config;
use FileVault\Persistence\Exception\ConnectionException;
use FileVault\Persistence\Exception\TransactionException;
use FileVault\Persistence\Migration\MigrationRunner;
use FileVault\Persistence\Repository\ProjectRepository;
use FileVault\Persistence\Repository\ApiKeyRepository;
use FileVault\Persistence\Repository\FileRepository;

final class Database
{
    private \PDO $pdo;

    public function __construct(Config $config)
    {
        $dbConfig = $config->database;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbConfig->host,
            $dbConfig->port,
            $dbConfig->database
        );

        try {
            $this->pdo = new \PDO($dsn, $dbConfig->username, $dbConfig->password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            // Avoid exposing $e->getMessage() as it might contain credentials or host details
            throw new ConnectionException('Failed to connect to the database.', 0, $e);
        }
    }

    public function createProjectRepository(): ProjectRepository
    {
        return new ProjectRepository($this->pdo);
    }

    public function createApiKeyRepository(): ApiKeyRepository
    {
        return new ApiKeyRepository($this->pdo);
    }

    public function createFileRepository(): FileRepository
    {
        return new FileRepository($this->pdo);
    }

    public function createMigrationRunner(string $migrationsPath): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $migrationsPath);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     */
    public function transaction(callable $callback)
    {
        try {
            $this->pdo->beginTransaction();
        } catch (\PDOException $e) {
            throw new TransactionException('Failed to begin transaction', 0, $e);
        }

        try {
            $result = $callback();
            
            try {
                $this->pdo->commit();
            } catch (\PDOException $e) {
                throw new TransactionException('Failed to commit transaction', 0, $e);
            }
            
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (\PDOException $rollbackException) {
                    // Swallow rollback exceptions to allow original exception to propagate
                }
            }
            throw $e;
        }
    }
}
