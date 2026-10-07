<?php

declare(strict_types=1);

namespace FileVault\Tests\Foundation\Config;

use FileVault\Foundation\Config\AppEnv;
use FileVault\Foundation\Config\ConfigException;
use FileVault\Foundation\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    private string $tempEnvPath;

    private array $envBackup = [];
    private array $serverBackup = [];
    private array $putenvBackup = [];

    private const ENV_KEYS = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    protected function setUp(): void
    {
        $this->tempEnvPath = sys_get_temp_dir() . '/' . uniqid('filevault_test_', true);
        mkdir($this->tempEnvPath);
        
        foreach (self::ENV_KEYS as $key) {
            $this->putenvBackup[$key] = getenv($key);
            $this->envBackup[$key] = $_ENV[$key] ?? null;
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;

            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempEnvPath . '/.env')) {
            unlink($this->tempEnvPath . '/.env');
        }
        rmdir($this->tempEnvPath);
        
        foreach (self::ENV_KEYS as $key) {
            if ($this->putenvBackup[$key] !== false) {
                putenv($key . '=' . $this->putenvBackup[$key]);
            } else {
                putenv($key);
            }
            
            if ($this->envBackup[$key] !== null) {
                $_ENV[$key] = $this->envBackup[$key];
            } else {
                unset($_ENV[$key]);
            }
            
            if ($this->serverBackup[$key] !== null) {
                $_SERVER[$key] = $this->serverBackup[$key];
            } else {
                unset($_SERVER[$key]);
            }
        }
    }

    private function createEnvFile(string $content): void
    {
        file_put_contents($this->tempEnvPath . '/.env', $content);
    }

    public function testLoadsValidConfigurationFromEnvFile(): void
    {
        $this->createEnvFile(
            "APP_ENV=development\n" .
            "APP_DEBUG=true\n" .
            "APP_URL=https://dev.example.com\n" .
            "DB_HOST=localhost\n" .
            "DB_PORT=3306\n" .
            "DB_DATABASE=filevault\n" .
            "DB_USERNAME=root\n" .
            "DB_PASSWORD=secret\n" .
            "STORAGE_ROOT=/tmp/storage\n"
        );

        $config = ConfigLoader::load($this->tempEnvPath);

        $this->assertSame(AppEnv::Development, $config->appEnv);
        $this->assertTrue($config->appDebug);
        $this->assertSame('https://dev.example.com', $config->appUrl);
    }

    public function testFailsWhenAppUrlIsMissing(): void
    {
        $this->createEnvFile(
            "APP_ENV=production\nAPP_DEBUG=false\n" .
            "DB_HOST=localhost\nDB_DATABASE=filevault\nDB_USERNAME=root\n" .
            "STORAGE_ROOT=/tmp/storage\n"
        );

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required configuration value for: APP_URL');

        ConfigLoader::load($this->tempEnvPath);
    }

    public function testFailsWhenAppUrlIsInvalid(): void
    {
        $this->createEnvFile(
            "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=not-a-url\n" .
            "DB_HOST=localhost\nDB_DATABASE=filevault\nDB_USERNAME=root\n" .
            "STORAGE_ROOT=/tmp/storage\n"
        );

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid configuration type for APP_URL. Expected valid URL.');

        ConfigLoader::load($this->tempEnvPath);
    }

    public function testFailsWhenAppEnvIsInvalid(): void
    {
        $this->createEnvFile(
            "APP_ENV=invalid\nAPP_DEBUG=false\nAPP_URL=https://example.com\n" .
            "DB_HOST=localhost\nDB_DATABASE=filevault\nDB_USERNAME=root\n" .
            "STORAGE_ROOT=/tmp/storage\n"
        );

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid configuration value for APP_ENV');

        ConfigLoader::load($this->tempEnvPath);
    }

    public function testDefaultValues(): void
    {
        $this->createEnvFile(
            "APP_URL=https://example.com\n" .
            "DB_HOST=localhost\nDB_DATABASE=filevault\nDB_USERNAME=root\n" .
            "STORAGE_ROOT=/tmp/storage\n"
        );

        $config = ConfigLoader::load($this->tempEnvPath);

        // APP_ENV defaults to production, APP_DEBUG defaults to false
        $this->assertSame(AppEnv::Production, $config->appEnv);
        $this->assertFalse($config->appDebug);
    }

    public function testEnvironmentVariablePrecedence(): void
    {
        // Set real environment variable
        putenv('APP_URL=https://real.example.com');
        $_ENV['APP_URL'] = 'https://real.example.com';
        $_SERVER['APP_URL'] = 'https://real.example.com';

        // Fake variable in .env
        $this->createEnvFile(
            "APP_ENV=development\n" .
            "APP_DEBUG=true\n" .
            "APP_URL=https://fake.example.com\n" .
            "DB_HOST=localhost\n" .
            "DB_PORT=3306\n" .
            "DB_DATABASE=filevault\n" .
            "DB_USERNAME=root\n" .
            "DB_PASSWORD=secret\n" .
            "STORAGE_ROOT=/tmp/storage\n"
        );

        $config = ConfigLoader::load($this->tempEnvPath);

        $this->assertSame('https://real.example.com', $config->appUrl);
    }
}
