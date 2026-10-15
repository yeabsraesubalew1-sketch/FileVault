<?php

declare(strict_types=1);

namespace FileVault\Tests\Http;

use FileVault\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    public function testMatchesSimpleRoute(): void
    {
        $router = new Router();
        $router->get('/health', fn () => 'ok');

        $match = $router->match('GET', '/health');
        $this->assertNotNull($match);
        $this->assertIsCallable($match['handler']);
        $this->assertSame([], $match['params']);
        $this->assertFalse($match['authRequired']);
    }

    public function testMatchesRouteWithParameter(): void
    {
        $router = new Router();
        $router->get('/api/v1/files/{id}', fn () => 'file', true);

        $match = $router->match('GET', '/api/v1/files/42');
        $this->assertNotNull($match);
        $this->assertSame(['id' => '42'], $match['params']);
        $this->assertTrue($match['authRequired']);
    }

    public function testMatchesWildcardStorageKey(): void
    {
        $router = new Router();
        $router->get('/files/{storageKey:.*}', fn () => 'deliver');

        $match = $router->match('GET', '/files/2026/10/a1b2c3d4e5f6');
        $this->assertNotNull($match);
        $this->assertSame(['storageKey' => '2026/10/a1b2c3d4e5f6'], $match['params']);
    }

    public function testDetectsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/api/v1/files', fn () => 'list');

        $match = $router->match('POST', '/api/v1/files');
        $this->assertNotNull($match);
        $this->assertArrayHasKey('methodNotAllowed', $match);
        $this->assertSame(['GET'], $match['methodNotAllowed']);
    }

    public function testReturnsNullForUnmatchedRoute(): void
    {
        $router = new Router();
        $router->get('/api/v1/files', fn () => 'list');

        $match = $router->match('GET', '/unknown');
        $this->assertNull($match);
    }

    public function testNormalizePathStripsSubdirectoryAndCleansSlashes(): void
    {
        $origAppUrl = getenv('APP_URL');
        $origEnv = $_ENV['APP_URL'] ?? null;
        $origScript = $_SERVER['SCRIPT_NAME'] ?? null;

        try {
            putenv('APP_URL=https://addisagents.com/website_973c67d0');
            $_ENV['APP_URL'] = 'https://addisagents.com/website_973c67d0';
            $_SERVER['SCRIPT_NAME'] = '/website_973c67d0/public/index.php';

            $this->assertSame('/', \FileVault\Http\Request::normalizePath('/website_973c67d0'));
            $this->assertSame('/', \FileVault\Http\Request::normalizePath('/website_973c67d0/'));
            $this->assertSame('/admin', \FileVault\Http\Request::normalizePath('/website_973c67d0/admin'));
            $this->assertSame('/admin', \FileVault\Http\Request::normalizePath('/website_973c67d0/admin/'));
            $this->assertSame('/api/v1/files', \FileVault\Http\Request::normalizePath('/website_973c67d0/api/v1/files'));
        } finally {
            if ($origAppUrl !== false) {
                putenv("APP_URL={$origAppUrl}");
            } else {
                putenv('APP_URL');
            }
            if ($origEnv !== null) {
                $_ENV['APP_URL'] = $origEnv;
            } else {
                unset($_ENV['APP_URL']);
            }
            if ($origScript !== null) {
                $_SERVER['SCRIPT_NAME'] = $origScript;
            } else {
                unset($_SERVER['SCRIPT_NAME']);
            }
        }
    }
}
