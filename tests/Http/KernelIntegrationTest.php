<?php

declare(strict_types=1);

namespace FileVault\Tests\Http;

use FileVault\Http\Kernel;
use FileVault\Http\Request;
use FileVault\Project\ProjectQuota;
use FileVault\Tests\Persistence\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Kernel::class)]
final class KernelIntegrationTest extends IntegrationTestCase
{
    private string $storageDir;
    private Kernel $kernel;
    private int $projectId;
    private string $apiKeyPlaintext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir() . '/fv_test_storage_' . uniqid();
        mkdir($this->storageDir);

        // Reflection to inject storage and database into App
        $app = \FileVault\Foundation\Bootstrap\App::bootstrap(dirname(__DIR__, 2));

        // Use test DB and temporary storage root
        $appConfig = new \FileVault\Foundation\Config\Config(
            \FileVault\Foundation\Config\AppEnv::Testing,
            true,
            'http://localhost',
            $app->getConfig()->database,
            new \FileVault\Foundation\Config\StorageConfig($this->storageDir)
        );

        $appReflection = new \ReflectionClass($app);
        $configProp = $appReflection->getProperty('config');
        $configProp->setValue($app, $appConfig);

        $dbProp = $appReflection->getProperty('database');
        $dbProp->setValue($app, $this->database);

        $this->kernel = new Kernel($app);

        // Create a test project and API key
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $projectService = $app->getProjectService();
        $project = $projectService->create(
            'Kernel Test ' . uniqid(),
            new ProjectQuota(5 * 1024 * 1024, 100, 50 * 1024 * 1024),
            $now
        );
        $this->projectId = $project->id;

        $createdKey = $app->getCreateApiKey()->create($this->projectId, 'Kernel Key', $now);
        $this->apiKeyPlaintext = $createdKey->plaintextCredential;
    }

    protected function tearDown(): void
    {
        // Cleanup storage
        $this->deleteRecursive($this->storageDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testHealthEndpoint(): void
    {
        $request = new Request('GET', '/health', '/health', [], [], [], [], 'req_test');
        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('FileVault', $data['app']);
    }

    public function testMissingApiKeyReturns401(): void
    {
        $request = new Request('GET', '/api/v1/files', '/api/v1/files', [], [], [], [], 'req_test');
        $response = $this->kernel->handle($request);

        $this->assertSame(401, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame('authentication_required', $data['error']['code']);
    }

    public function testInvalidApiKeyReturns401(): void
    {
        $request = new Request('GET', '/api/v1/files', '/api/v1/files', ['x-api-key' => 'fvk_invalid_cred_key'], [], [], [], 'req_test');
        $response = $this->kernel->handle($request);

        $this->assertSame(401, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame('invalid_api_key', $data['error']['code']);
    }

    public function testGetProjectEndpoint(): void
    {
        $request = new Request('GET', '/api/v1/project', '/api/v1/project', ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_test');
        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame($this->projectId, $data['project']['id']);
        $this->assertSame('active', $data['project']['status']);
    }

    public function testUploadRejectsProhibitedExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_php_');
        file_put_contents($tmp, '<?php echo 1;');

        $files = [
            'file' => [
                'name' => 'malicious.php',
                'type' => 'application/x-php',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ],
        ];

        $request = new Request('POST', '/api/v1/files', '/api/v1/files', ['x-api-key' => $this->apiKeyPlaintext], [], [], $files, 'req_test');
        $response = $this->kernel->handle($request);

        @unlink($tmp);

        $this->assertSame(415, $response->statusCode);
        $data = json_decode($response->body, true);
        $this->assertSame('file_type_not_allowed', $data['error']['code']);
    }

    public function testUploadListGetDeliveryDeleteFlow(): void
    {
        // 1. Upload valid image
        $tmp = tempnam(sys_get_temp_dir(), 'test_png_');
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        file_put_contents($tmp, $pngBytes);

        $files = [
            'file' => [
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($pngBytes),
            ],
        ];

        $uploadReq = new Request('POST', '/api/v1/files', '/api/v1/files', ['x-api-key' => $this->apiKeyPlaintext], [], [], $files, 'req_up');
        $uploadRes = $this->kernel->handle($uploadReq);

        @unlink($tmp);

        $this->assertSame(201, $uploadRes->statusCode);
        $uploadData = json_decode($uploadRes->body, true);
        $this->assertArrayHasKey('file', $uploadData);
        $file = $uploadData['file'];
        $fileId = (int) $file['id'];
        $storageKey = (string) $file['storage_key'];

        $this->assertSame('pixel.png', $file['original_name']);
        $this->assertSame('image/png', $file['mime_type']);
        $this->assertSame('active', $file['status']);
        $this->assertStringContainsString('/files/' . $storageKey, $file['url']);

        // 2. List Files
        $listReq = new Request('GET', '/api/v1/files', '/api/v1/files', ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_list');
        $listRes = $this->kernel->handle($listReq);

        $this->assertSame(200, $listRes->statusCode);
        $listData = json_decode($listRes->body, true);
        $this->assertCount(1, $listData['files']);
        $this->assertSame($fileId, $listData['files'][0]['id']);

        // 3. Get Single File
        $getReq = new Request('GET', '/api/v1/files/' . $fileId, '/api/v1/files/' . $fileId, ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_get');
        $getRes = $this->kernel->handle($getReq);

        $this->assertSame(200, $getRes->statusCode);
        $getData = json_decode($getRes->body, true);
        $this->assertSame($fileId, $getData['file']['id']);

        // 4. Public File Delivery via /files/{storageKey}
        $delivReq = new Request('GET', '/files/' . $storageKey, '/files/' . $storageKey, [], [], [], [], 'req_pub');
        $delivRes = $this->kernel->handle($delivReq);

        $this->assertSame(200, $delivRes->statusCode);
        $this->assertSame('image/png', $delivRes->headers['Content-Type']);
        $this->assertSame((string) strlen($pngBytes), $delivRes->headers['Content-Length']);
        $this->assertArrayHasKey('ETag', $delivRes->headers);
        $delivRes->closeStream();

        // 5. Public Delivery via /f/{id}
        $delivIdReq = new Request('GET', '/f/' . $fileId, '/f/' . $fileId, [], [], [], [], 'req_pub_id');
        $delivIdRes = $this->kernel->handle($delivIdReq);
        $this->assertSame(200, $delivIdRes->statusCode);
        $delivIdRes->closeStream();

        // 6. Conditional GET with ETag -> 304 Not Modified
        $etag = $delivRes->headers['ETag'];
        $etagReq = new Request('GET', '/files/' . $storageKey, '/files/' . $storageKey, ['if-none-match' => $etag], [], [], [], 'req_etag');
        $etagRes = $this->kernel->handle($etagReq);
        $this->assertSame(304, $etagRes->statusCode);

        // 7. Delete File (soft delete -> trash)
        $deleteReq = new Request('DELETE', '/api/v1/files/' . $fileId, '/api/v1/files/' . $fileId, ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_del');
        $deleteRes = $this->kernel->handle($deleteReq);

        $this->assertSame(200, $deleteRes->statusCode);
        $deleteData = json_decode($deleteRes->body, true);
        $this->assertSame('trashed', $deleteData['status']);

        // 8. Public Delivery of Trashed File -> 404
        $delivAfterTrashReq = new Request('GET', '/files/' . $storageKey, '/files/' . $storageKey, [], [], [], [], 'req_pub_del');
        $delivAfterTrashRes = $this->kernel->handle($delivAfterTrashReq);
        $this->assertSame(404, $delivAfterTrashRes->statusCode);

        // 8b. Authenticated List Trashed Files -> 200
        $listTrashReq = new Request('GET', '/api/v1/files/trash', '/api/v1/files/trash', ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_list_trash');
        $listTrashRes = $this->kernel->handle($listTrashReq);
        $this->assertSame(200, $listTrashRes->statusCode);
        $listTrashData = json_decode($listTrashRes->body, true);
        $this->assertCount(1, $listTrashData['files']);
        $this->assertSame($fileId, $listTrashData['files'][0]['id']);

        // 8c. Authenticated Download of Trashed File -> 200 with Content
        $authDownloadReq = new Request('GET', '/api/v1/files/' . $fileId . '/download', '/api/v1/files/' . $fileId . '/download', ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_auth_dl');
        $authDownloadRes = $this->kernel->handle($authDownloadReq);
        $this->assertSame(200, $authDownloadRes->statusCode);
        $this->assertSame('image/png', $authDownloadRes->headers['Content-Type']);
        $this->assertStringContainsString('attachment', $authDownloadRes->headers['Content-Disposition']);
        $authDownloadRes->closeStream();

        // 9. Restore Trashed File via trash alias
        $restoreReq = new Request('POST', '/api/v1/files/trash/' . $fileId . '/restore', '/api/v1/files/trash/' . $fileId . '/restore', ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_res');
        $restoreRes = $this->kernel->handle($restoreReq);

        $this->assertSame(200, $restoreRes->statusCode);
        $restoreData = json_decode($restoreRes->body, true);
        $this->assertSame('restored', $restoreData['status']);

        // 10. Purge Restored File -> first trash it, then purge via trash alias
        $reTrashReq = new Request('DELETE', '/api/v1/files/' . $fileId, '/api/v1/files/' . $fileId, ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_del2');
        $this->kernel->handle($reTrashReq);

        $purgeReq = new Request('DELETE', '/api/v1/files/trash/' . $fileId, '/api/v1/files/trash/' . $fileId, ['x-api-key' => $this->apiKeyPlaintext], [], [], [], 'req_purge');
        $purgeRes = $this->kernel->handle($purgeReq);

        $this->assertSame(200, $purgeRes->statusCode);
        $purgeData = json_decode($purgeRes->body, true);
        $this->assertSame('purged', $purgeData['status']);
    }
}
