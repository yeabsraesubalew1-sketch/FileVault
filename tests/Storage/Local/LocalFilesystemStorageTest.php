<?php

declare(strict_types=1);

namespace FileVault\Tests\Storage\Local;

use FileVault\Foundation\Config\StorageConfig;
use FileVault\Storage\Exception\StorageConfigurationException;
use FileVault\Storage\Exception\StorageConflictException;
use FileVault\Storage\Exception\StorageObjectNotFoundException;
use FileVault\Storage\Exception\StorageOperationException;
use FileVault\Storage\Local\LocalFilesystemStorage;
use PHPUnit\Framework\TestCase;

class LocalFilesystemStorageTest extends TestCase
{
    private string $storageRoot;
    private LocalFilesystemStorage $storage;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->storageRoot = sys_get_temp_dir() . '/filevault_test_' . uniqid();
        mkdir($this->storageRoot, 0700, true);
        
        $config = new StorageConfig($this->storageRoot);
        $this->storage = new LocalFilesystemStorage($config);
    }
    
    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }
    
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    public function testStorageRootCannotBeEmpty(): void
    {
        $this->expectException(StorageConfigurationException::class);
        new LocalFilesystemStorage(new StorageConfig(''));
    }
    
    public function testInvalidProjectId(): void
    {
        $this->expectException(StorageOperationException::class);
        $this->expectExceptionMessage('Project ID must be a positive integer');
        
        $stream = fopen('php://temp', 'rb');
        $this->storage->store(0, $stream);
    }
    
    public function testStoreSuccessfully(): void
    {
        $content = 'Hello World';
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        
        $storedObject = $this->storage->store(42, $stream);
        
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{64}$#', $storedObject->storageKey);
        $this->assertSame(strlen($content), $storedObject->sizeBytes);
        
        // Ensure temp directory is empty
        $tempDir = $this->storageRoot . '/.tmp';
        if (is_dir($tempDir)) {
            $this->assertCount(2, scandir($tempDir)); // . and ..
        }
        
        // Ensure final file exists and content matches
        $finalPath = sprintf('%s/projects/42/active/%s', $this->storageRoot, $storedObject->storageKey);
        $this->assertFileExists($finalPath);
        $this->assertSame($content, file_get_contents($finalPath));
    }
    
    public function testStoreRequiresResource(): void
    {
        $this->expectException(StorageOperationException::class);
        $this->expectExceptionMessage('Source must be a resource');
        
        $this->storage->store(1, 'not a stream');
    }
    
    public function testOpenReadSuccessfully(): void
    {
        $content = 'Test Read';
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        
        $storedObject = $this->storage->store(100, $stream);
        
        $readStream = $this->storage->openRead(100, $storedObject->storageKey);
        
        $this->assertIsResource($readStream);
        $this->assertSame($content, stream_get_contents($readStream));
        fclose($readStream);
    }
    
    public function testOpenReadMissingObject(): void
    {
        $this->expectException(StorageObjectNotFoundException::class);
        
        $key = gmdate('Y/m/') . str_repeat('a', 64);
        $this->storage->openRead(1, $key);
    }
    
    public function testOpenReadInvalidKey(): void
    {
        $this->expectException(StorageOperationException::class);
        $this->expectExceptionMessage('Invalid storage key format');
        
        $this->storage->openRead(1, 'invalid-key');
    }
    
    public function testMoveToTrash(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'trash me');
        rewind($stream);
        
        $storedObject = $this->storage->store(2, $stream);
        
        $activePath = sprintf('%s/projects/2/active/%s', $this->storageRoot, $storedObject->storageKey);
        $trashPath = sprintf('%s/projects/2/trash/%s', $this->storageRoot, $storedObject->storageKey);
        
        $this->assertFileExists($activePath);
        $this->assertFileDoesNotExist($trashPath);
        
        $this->storage->moveToTrash(2, $storedObject->storageKey);
        
        $this->assertFileDoesNotExist($activePath);
        $this->assertFileExists($trashPath);
    }
    
    public function testMoveToTrashConflict(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'trash conflict');
        rewind($stream);
        
        $storedObject = $this->storage->store(10, $stream);
        
        $trashPath = sprintf('%s/projects/10/trash/%s', $this->storageRoot, $storedObject->storageKey);
        $trashDir = dirname($trashPath);
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0700, true);
        }
        file_put_contents($trashPath, 'conflict data');
        
        $this->expectException(StorageConflictException::class);
        $this->storage->moveToTrash(10, $storedObject->storageKey);
    }
    
    public function testMoveToTrashUnlinkFailureThrowsException(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'unlink fail');
        rewind($stream);
        
        $storedObject = $this->storage->store(11, $stream);
        $activePath = sprintf('%s/projects/11/active/%s', $this->storageRoot, $storedObject->storageKey);
        $trashPath = sprintf('%s/projects/11/trash/%s', $this->storageRoot, $storedObject->storageKey);
        
        // Remove write permission from both the active directory (Linux) and the file itself (Windows) so unlink fails
        chmod(dirname($activePath), 0555);
        chmod($activePath, 0444);
        
        try {
            $this->storage->moveToTrash(11, $storedObject->storageKey);
            $this->fail('Expected StorageOperationException was not thrown');
        } catch (StorageOperationException $e) {
            $this->assertStringContainsString('unlink failed', $e->getMessage());
        } finally {
            // Restore permissions so tearDown can clean up
            chmod($activePath, 0777);
            chmod(dirname($activePath), 0777);
            chmod($trashPath, 0777); // In case the link inherited the read-only flag
        }
        
        // Verify partial relocation state: both exist
        $this->assertFileExists($activePath);
        $this->assertFileExists($trashPath);
    }
    
    public function testRestore(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'restore me');
        rewind($stream);
        
        $storedObject = $this->storage->store(3, $stream);
        $this->storage->moveToTrash(3, $storedObject->storageKey);
        
        $activePath = sprintf('%s/projects/3/active/%s', $this->storageRoot, $storedObject->storageKey);
        $trashPath = sprintf('%s/projects/3/trash/%s', $this->storageRoot, $storedObject->storageKey);
        
        $this->assertFileDoesNotExist($activePath);
        $this->assertFileExists($trashPath);
        
        $this->storage->restore(3, $storedObject->storageKey);
        
        $this->assertFileExists($activePath);
        $this->assertFileDoesNotExist($trashPath);
    }
    
    public function testRestoreConflict(): void
    {
        $stream1 = fopen('php://memory', 'r+b');
        fwrite($stream1, 'one');
        rewind($stream1);
        
        $storedObject = $this->storage->store(4, $stream1);
        $this->storage->moveToTrash(4, $storedObject->storageKey);
        
        // Manually create conflict at active path
        $activePath = sprintf('%s/projects/4/active/%s', $this->storageRoot, $storedObject->storageKey);
        $activeDir = dirname($activePath);
        if (!is_dir($activeDir)) {
            mkdir($activeDir, 0700, true);
        }
        file_put_contents($activePath, 'conflict');
        
        $this->expectException(StorageConflictException::class);
        $this->storage->restore(4, $storedObject->storageKey);
    }
    
    public function testRestoreUnlinkFailureThrowsException(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'restore unlink fail');
        rewind($stream);
        
        $storedObject = $this->storage->store(12, $stream);
        $this->storage->moveToTrash(12, $storedObject->storageKey);
        
        $activePath = sprintf('%s/projects/12/active/%s', $this->storageRoot, $storedObject->storageKey);
        $trashPath = sprintf('%s/projects/12/trash/%s', $this->storageRoot, $storedObject->storageKey);
        
        // Remove write permission from both the trash directory (Linux) and the file itself (Windows) so unlink fails
        chmod(dirname($trashPath), 0555);
        chmod($trashPath, 0444);
        
        try {
            $this->storage->restore(12, $storedObject->storageKey);
            $this->fail('Expected StorageOperationException was not thrown');
        } catch (StorageOperationException $e) {
            $this->assertStringContainsString('unlink failed', $e->getMessage());
        } finally {
            // Restore permissions so tearDown can clean up
            chmod($trashPath, 0777);
            chmod(dirname($trashPath), 0777);
            chmod($activePath, 0777); // In case the link inherited the read-only flag
        }
        
        // Verify partial relocation state: both exist
        $this->assertFileExists($trashPath);
        $this->assertFileExists($activePath);
    }
    
    public function testPurge(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'purge me');
        rewind($stream);
        
        $storedObject = $this->storage->store(5, $stream);
        $this->storage->moveToTrash(5, $storedObject->storageKey);
        
        $trashPath = sprintf('%s/projects/5/trash/%s', $this->storageRoot, $storedObject->storageKey);
        $this->assertFileExists($trashPath);
        
        $this->storage->purge(5, $storedObject->storageKey);
        
        $this->assertFileDoesNotExist($trashPath);
    }
    
    public function testIsolationDifferentProject(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'proj1');
        rewind($stream);
        
        $storedObject = $this->storage->store(1, $stream);
        
        $this->expectException(StorageObjectNotFoundException::class);
        $this->storage->openRead(2, $storedObject->storageKey);
    }
    
    public function testStorageRootMustBeAbsolute(): void
    {
        $this->expectException(StorageConfigurationException::class);
        $this->expectExceptionMessage('Storage root must be an absolute path');
        
        new LocalFilesystemStorage(new StorageConfig('storage'));
    }
    
    public function testStorageRootAbsoluteAccepted(): void
    {
        // Absolute unix style
        $config = new StorageConfig('/tmp/absolute/path');
        $this->assertInstanceOf(LocalFilesystemStorage::class, new LocalFilesystemStorage($config));
        
        // Absolute windows style
        $configWin = new StorageConfig('C:\temp\path');
        $this->assertInstanceOf(LocalFilesystemStorage::class, new LocalFilesystemStorage($configWin));
    }
    
    public function testStoreSetsCorrectFilePermissions(): void
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'permissions test');
        rewind($stream);
        
        $storedObject = $this->storage->store(6, $stream);
        
        $finalPath = sprintf('%s/projects/6/active/%s', $this->storageRoot, $storedObject->storageKey);
        
        $this->assertFileExists($finalPath);
        
        // On Windows, permissions might not exactly map to 0600 or 0700 depending on the underlying fs.
        // But we can check that it's applied. 0600 = 384 decimal. The result is often masked by OS.
        // We'll verify it doesn't throw and permissions are reasonably what PHP returns.
        $filePerms = fileperms($finalPath) & 0777;
        
        // Skip exact permission checks on Windows if the filesystem doesn't support unix permissions accurately
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertEquals(0600, $filePerms);
            
            $dirPerms = fileperms(dirname($finalPath)) & 0777;
            $this->assertEquals(0700, $dirPerms);
        } else {
            $this->addToAssertionCount(1);
        }
    }
    
    public function testValidatesStorageKeyMonth(): void
    {
        $token = str_repeat('a', 64);
        
        // Valid 01
        $this->expectException(StorageObjectNotFoundException::class);
        $this->storage->openRead(1, '2026/01/' . $token);
    }
    
    public function testValidatesStorageKeyMonth12(): void
    {
        $token = str_repeat('a', 64);
        
        // Valid 12
        $this->expectException(StorageObjectNotFoundException::class);
        $this->storage->openRead(1, '2026/12/' . $token);
    }
    
    public function testRejectsStorageKeyMonth00(): void
    {
        $token = str_repeat('a', 64);
        
        $this->expectException(StorageOperationException::class);
        $this->expectExceptionMessage('Invalid storage key format');
        $this->storage->openRead(1, '2026/00/' . $token);
    }
    
    public function testRejectsStorageKeyMonth13(): void
    {
        $token = str_repeat('a', 64);
        
        $this->expectException(StorageOperationException::class);
        $this->expectExceptionMessage('Invalid storage key format');
        $this->storage->openRead(1, '2026/13/' . $token);
    }
    
    public function testRejectsStorageKeyInvalidFormat(): void
    {
        $token = str_repeat('a', 64);
        
        $this->expectException(StorageOperationException::class);
        $this->expectExceptionMessage('Invalid storage key format');
        $this->storage->openRead(1, '2026/1/' . $token);
    }
}
