<?php

declare(strict_types=1);

namespace FileVault\Persistence\Migration;

use FileVault\Persistence\Database;
use FileVault\Persistence\Exception\MigrationException;

final class MigrationRunner
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsPath
    ) {}

    public function run(): void
    {
        $this->ensureTrackingTableExists($this->pdo);
        
        $applied = $this->getAppliedVersions($this->pdo);
        $files = $this->discoverMigrations();

        foreach ($files as $version => $file) {
            if (!in_array($version, $applied, true)) {
                $this->executeMigration($this->pdo, $version, $file);
            }
        }
    }

    private function ensureTrackingTableExists(\PDO $pdo): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                version     VARCHAR(100) NOT NULL,
                applied_at  DATETIME(6) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schema_migrations_version (version)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;
        
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new MigrationException('Failed to ensure schema_migrations table exists.', 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    private function getAppliedVersions(\PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("SELECT version FROM schema_migrations ORDER BY id ASC");
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            throw new MigrationException('Failed to retrieve applied migrations.', 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function discoverMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new MigrationException("Migrations directory not found: {$this->migrationsPath}");
        }

        $files = glob(rtrim($this->migrationsPath, '/\\') . '/*.sql');
        if ($files === false) {
            throw new MigrationException('Failed to read migrations directory.');
        }

        $migrations = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (!preg_match('/^(\d+)_.*\.sql$/', $basename, $matches)) {
                throw new MigrationException("Invalid migration filename: {$basename}");
            }
            
            $version = $matches[1];
            if (isset($migrations[$version])) {
                throw new MigrationException("Duplicate migration version detected: {$version}");
            }
            
            $migrations[$version] = $file;
        }

        ksort($migrations, SORT_STRING); // Sort alphanumerically e.g. 001, 002
        return $migrations;
    }

    private function executeMigration(\PDO $pdo, string $version, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new MigrationException("Failed to read migration file: {$file}");
        }

        try {
            $pdo->exec($sql);
            
            $stmt = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)");
            $stmt->execute([
                $version,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')
            ]);
        } catch (\PDOException $e) {
            throw new MigrationException("Failed to execute migration {$version}.", 0, $e);
        }
    }
}
