<?php

declare(strict_types=1);

namespace FileVault\Tests\Http;

use FileVault\ApiKey\ApiKeyStatus;
use FileVault\Foundation\Bootstrap\App;
use FileVault\Foundation\Config\AppEnv;
use FileVault\Foundation\Config\Config;
use FileVault\Foundation\Config\StorageConfig;
use FileVault\Http\Controller\AdminController;
use FileVault\Http\Kernel;
use FileVault\Http\Request;
use FileVault\Project\ProjectQuota;
use FileVault\Tests\Persistence\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;

#[CoversClass(AdminController::class)]
final class AdminControllerTest extends IntegrationTestCase
{
    private string $storageDir;
    private Kernel $kernel;
    private App $app;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        session_id(bin2hex(random_bytes(16)));

        $this->storageDir = sys_get_temp_dir() . '/fv_admin_test_' . uniqid();
        mkdir($this->storageDir);

        $this->app = App::bootstrap(dirname(__DIR__, 2));

        $appConfig = new Config(
            AppEnv::Testing,
            true,
            'http://localhost',
            $this->app->getConfig()->database,
            new StorageConfig($this->storageDir),
            adminPassword: 'secretpassword123',
            adminUsername: 'superadmin'
        );

        $appReflection = new ReflectionClass($this->app);
        $configProp = $appReflection->getProperty('config');
        $configProp->setValue($this->app, $appConfig);

        $dbProp = $appReflection->getProperty('database');
        $dbProp->setValue($this->app, $this->database);

        $this->kernel = new Kernel($this->app);

        // Pre-create a test project
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $project = $this->app->getProjectService()->create(
            'Admin Test ' . uniqid(),
            new ProjectQuota(5 * 1024 * 1024, 100, 50 * 1024 * 1024),
            $now
        );
        $this->projectId = $project->id;
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    private function authenticateSession(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['filevault_admin_logged_in'] = true;
        $_SESSION['filevault_admin_user'] = 'superadmin';
        $_SESSION['admin_csrf'] = 'valid_test_csrf_' . uniqid();
        return $_SESSION['admin_csrf'];
    }

    private function createRequest(
        string $method,
        string $uri,
        array $headers = [],
        array $body = [],
        array $queryParams = []
    ): Request {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $normalizedHeaders = [];
        foreach ($headers as $k => $v) {
            $normalizedHeaders[strtolower($k)] = (string) $v;
        }

        return new Request(
            method: strtoupper($method),
            uri: $uri,
            path: rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
            headers: $normalizedHeaders,
            queryParams: $queryParams,
            parsedBody: $body,
            files: [],
            requestId: 'req_admin_test'
        );
    }

    public function testLoginViewReturnsHtml(): void
    {
        $req = $this->createRequest('GET', '/admin/login');
        $res = $this->kernel->handle($req);

        $this->assertSame(200, $res->statusCode);
        $this->assertStringContainsString('text/html', $res->headers['Content-Type'] ?? '');
        $this->assertStringContainsString('FileVault Admin', $res->body ?? '');
        $this->assertNotEmpty($_SESSION['admin_csrf'] ?? '');
    }

    public function testLoginRejectsInvalidCredentials(): void
    {
        // First visit to get CSRF
        $this->kernel->handle($this->createRequest('GET', '/admin/login'));
        $csrf = $_SESSION['admin_csrf'];

        $postReq = $this->createRequest('POST', '/admin/login', [], [
            'username' => 'superadmin',
            'password' => 'wrongpassword',
            'csrf_token' => $csrf,
        ]);
        $res = $this->kernel->handle($postReq);

        $this->assertSame(302, $res->statusCode);
        $this->assertSame('/admin/login', $res->headers['Location'] ?? '');
        $this->assertFalse($_SESSION['filevault_admin_logged_in'] ?? false);
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    public function testLoginSucceedsWithValidCredentials(): void
    {
        $this->kernel->handle($this->createRequest('GET', '/admin/login'));
        $csrf = $_SESSION['admin_csrf'];

        $postReq = $this->createRequest('POST', '/admin/login', [], [
            'username' => 'superadmin',
            'password' => 'secretpassword123',
            'csrf_token' => $csrf,
        ]);
        $res = $this->kernel->handle($postReq);

        $this->assertSame(302, $res->statusCode);
        $this->assertSame('/admin', $res->headers['Location'] ?? '');
        $this->assertTrue($_SESSION['filevault_admin_logged_in'] ?? false);
    }

    public function testDashboardRedirectsWhenUnauthenticated(): void
    {
        $req = $this->createRequest('GET', '/admin');
        $res = $this->kernel->handle($req);

        $this->assertSame(302, $res->statusCode);
        $this->assertSame('/admin/login', $res->headers['Location'] ?? '');
    }

    public function testDashboardRendersWhenAuthenticated(): void
    {
        $this->authenticateSession();

        $req = $this->createRequest('GET', '/admin');
        $res = $this->kernel->handle($req);

        $this->assertSame(200, $res->statusCode);
        $this->assertStringContainsString('Registered Projects', $res->body ?? '');
        $this->assertStringContainsString('Active Projects', $res->body ?? '');
    }

    public function testCreateProjectFlow(): void
    {
        $csrf = $this->authenticateSession();

        $name = 'Admin Created ' . uniqid();
        $req = $this->createRequest('POST', '/admin/projects', [], [
            'name' => $name,
            'max_storage' => '2G',
            'max_file_size' => '25M',
            'max_file_count' => '500',
            'csrf_token' => $csrf,
        ]);

        $res = $this->kernel->handle($req);
        $this->assertSame(302, $res->statusCode);
        $this->assertSame('/admin', $res->headers['Location'] ?? '');

        // Verify project exists in database
        $created = $this->app->getProjectRepository()->findByName($name);
        $this->assertNotNull($created);
        $this->assertSame(2 * 1024 * 1024 * 1024, $created->getQuota()->maxStorageBytes);
        $this->assertSame(25 * 1024 * 1024, $created->getQuota()->maxFileSize);
        $this->assertSame(500, $created->getQuota()->maxFileCount);
    }

    public function testUpdateProjectStatusAndQuota(): void
    {
        $csrf = $this->authenticateSession();

        // Update status to restricted
        $req = $this->createRequest('POST', "/admin/projects/{$this->projectId}/status", [], [
            'status' => 'restricted',
            'csrf_token' => $csrf,
        ]);
        $res = $this->kernel->handle($req);
        $this->assertSame(302, $res->statusCode);

        $project = $this->app->getProjectRepository()->findById($this->projectId);
        $this->assertSame('restricted', $project->getStatus()->value);

        // Update quota
        $quotaReq = $this->createRequest('POST', "/admin/projects/{$this->projectId}/quota", [], [
            'max_storage' => '500M',
            'max_file_size' => '10M',
            'max_file_count' => '200',
            'csrf_token' => $csrf,
        ]);
        $quotaRes = $this->kernel->handle($quotaReq);
        $this->assertSame(302, $quotaRes->statusCode);

        $projectUpdated = $this->app->getProjectRepository()->findById($this->projectId);
        $this->assertSame(500 * 1024 * 1024, $projectUpdated->getQuota()->maxStorageBytes);
        $this->assertSame(10 * 1024 * 1024, $projectUpdated->getQuota()->maxFileSize);
        $this->assertSame(200, $projectUpdated->getQuota()->maxFileCount);
    }

    public function testCreateAndRevokeApiKeyFlow(): void
    {
        $csrf = $this->authenticateSession();

        // Create API Key
        $createReq = $this->createRequest('POST', "/admin/projects/{$this->projectId}/keys", [], [
            'name' => 'Automated Integration Key',
            'csrf_token' => $csrf,
        ]);
        $createRes = $this->kernel->handle($createReq);
        $this->assertSame(302, $createRes->statusCode);

        // Check new key flash
        $flash = $_SESSION['flash_new_key'] ?? null;
        $this->assertNotNull($flash);
        $this->assertStringStartsWith('fvk_', $flash['plaintext']);
        $keyId = $flash['keyId'];

        // Revoke API Key
        $revokeReq = $this->createRequest('POST', "/admin/keys/{$keyId}/revoke", [], [
            'csrf_token' => $csrf,
        ]);
        $revokeRes = $this->kernel->handle($revokeReq);
        $this->assertSame(302, $revokeRes->statusCode);

        $keys = $this->app->getApiKeyRepository()->findByProject($this->projectId);
        $revokedKey = null;
        foreach ($keys as $k) {
            if ($k->id === $keyId) {
                $revokedKey = $k;
                break;
            }
        }

        $this->assertNotNull($revokedKey);
        $this->assertSame(ApiKeyStatus::REVOKED, $revokedKey->status);
    }

    public function testLogoutFlow(): void
    {
        $_SESSION['filevault_admin_logged_in'] = true;

        $req = $this->createRequest('GET', '/admin/logout');
        $res = $this->kernel->handle($req);

        $this->assertSame(302, $res->statusCode);
        $this->assertSame('/admin/login', $res->headers['Location'] ?? '');
        $this->assertFalse($_SESSION['filevault_admin_logged_in'] ?? false);
    }
}
