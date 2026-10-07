<?php

declare(strict_types=1);

namespace FileVault\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use FileVault\Foundation\Config\DatabaseConfig;
use FileVault\Foundation\Config\Config;
use FileVault\Foundation\Config\AppEnv;
use FileVault\Persistence\Database;
use FileVault\Persistence\Migration\MigrationRunner;

abstract class IntegrationTestCase extends TestCase
{
    protected ?Database $database = null;

    protected function setUp(): void
    {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        $dbname = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'filevault_test';
        $user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

        $dbConfig = new DatabaseConfig($host, $port, $dbname, $user, $pass);
        $storageConfig = new \FileVault\Foundation\Config\StorageConfig(sys_get_temp_dir());
        $config = new Config(AppEnv::Testing, true, 'http://localhost', $dbConfig, $storageConfig);

        try {
            $this->database = new Database($config);
        } catch (\FileVault\Persistence\Exception\ConnectionException $e) {
            $this->markTestSkipped('Database connection failed. Ensure MySQL test database is configured and running: ' . $e->getMessage());
        }

        try {
            $runner = $this->database->createMigrationRunner(__DIR__ . '/../../database/migrations');
            $runner->run();
        } catch (\Exception $e) {
            $this->fail('Migrations failed to run in test setup: ' . $e->getMessage());
        }
        
        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->database) {
            $reflection = new \ReflectionClass($this->database);
            $pdo = $reflection->getProperty('pdo')->getValue($this->database);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
