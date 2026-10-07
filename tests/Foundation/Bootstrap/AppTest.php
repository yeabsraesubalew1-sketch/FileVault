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

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/' . uniqid('filevault_app_test_', true);
        mkdir($this->tempPath);
        
        putenv('APP_ENV=testing'); $_ENV['APP_ENV'] = 'testing';
        putenv('APP_DEBUG=true'); $_ENV['APP_DEBUG'] = 'true';
        putenv('APP_URL=https://test.example.com'); $_ENV['APP_URL'] = 'https://test.example.com';
        putenv('STORAGE_ROOT=/tmp/storage'); $_ENV['STORAGE_ROOT'] = '/tmp/storage';
    }

    protected function tearDown(): void
    {
        rmdir($this->tempPath);
        putenv('APP_ENV');
        putenv('APP_DEBUG');
        putenv('APP_URL');
        putenv('DB_HOST');
        putenv('DB_PORT');
        putenv('DB_DATABASE');
        putenv('DB_USERNAME');
        putenv('DB_PASSWORD');
        putenv('STORAGE_ROOT');
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
