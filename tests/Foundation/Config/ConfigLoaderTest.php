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

    protected function setUp(): void
    {
        $this->tempEnvPath = sys_get_temp_dir() . '/' . uniqid('filevault_test_', true);
        mkdir($this->tempEnvPath);
        
        // Clear env vars that might affect tests
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('APP_URL');
        putenv('DB_HOST');
        putenv('DB_PORT');
        putenv('DB_DATABASE');
        putenv('DB_USERNAME');
        putenv('DB_PASSWORD');
        unset($_ENV['APP_ENV'], $_ENV['APP_DEBUG'], $_ENV['APP_URL'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
        unset($_SERVER['APP_ENV'], $_SERVER['APP_DEBUG'], $_SERVER['APP_URL'], $_SERVER['DB_HOST'], $_SERVER['DB_PORT'], $_SERVER['DB_DATABASE'], $_SERVER['DB_USERNAME'], $_SERVER['DB_PASSWORD']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempEnvPath . '/.env')) {
            unlink($this->tempEnvPath . '/.env');
        }
        rmdir($this->tempEnvPath);
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('APP_URL');
        putenv('DB_HOST');
        putenv('DB_PORT');
        putenv('DB_DATABASE');
        putenv('DB_USERNAME');
        putenv('DB_PASSWORD');
        unset($_ENV['APP_ENV'], $_ENV['APP_DEBUG'], $_ENV['APP_URL'], $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_DATABASE'], $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
        unset($_SERVER['APP_ENV'], $_SERVER['APP_DEBUG'], $_SERVER['APP_URL'], $_SERVER['DB_HOST'], $_SERVER['DB_PORT'], $_SERVER['DB_DATABASE'], $_SERVER['DB_USERNAME'], $_SERVER['DB_PASSWORD']);
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
