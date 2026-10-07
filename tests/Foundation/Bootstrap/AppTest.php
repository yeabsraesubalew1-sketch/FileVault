<?php

declare(strict_types=1);

namespace FileVault\Tests\Foundation\Bootstrap;

use FileVault\Foundation\Bootstrap\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(App::class)]
final class AppTest extends TestCase
{
    private string $tempPath;

    private array $envBackup = [];
    private array $serverBackup = [];
    private array $putenvBackup = [];

    private const ENV_KEYS = ['APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'STORAGE_ROOT'];

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/' . uniqid('filevault_app_test_', true);
        mkdir($this->tempPath);
        
        foreach (self::ENV_KEYS as $key) {
            $this->putenvBackup[$key] = getenv($key);
            $this->envBackup[$key] = $_ENV[$key] ?? null;
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
        }

        putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = 'testing';
        putenv('APP_DEBUG=true'); $_ENV['APP_DEBUG'] = 'true';
        putenv('APP_URL=https://test.example.com'); $_ENV['APP_URL'] = 'https://test.example.com';
        putenv('STORAGE_ROOT=/tmp/storage'); $_ENV['STORAGE_ROOT'] = '/tmp/storage';
    }

    protected function tearDown(): void
    {
        rmdir($this->tempPath);
        
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

    public function testBootstrapReturnsAppWithConfig(): void
    {
        $app = App::bootstrap($this->tempPath);

        $this->assertInstanceOf(App::class, $app);
        $this->assertSame('https://test.example.com', $app->getConfig()->appUrl);
    }

    public function testBootstrapThrowsExceptionOnFailure(): void
    {
        // Remove required configuration
        putenv('APP_URL');
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

        $this->expectException(\FileVault\Foundation\Config\ConfigException::class);
        $this->expectExceptionMessage('Missing required configuration value for: APP_URL');

        App::bootstrap($this->tempPath);
    }
}
