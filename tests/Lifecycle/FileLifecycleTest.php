<?php

declare(strict_types=1);

namespace FileVault\Tests\Lifecycle;

use FileVault\Tests\Persistence\IntegrationTestCase;
use FileVault\Lifecycle\FileLifecycle;
use FileVault\Storage\StorageBackend;
use FileVault\Storage\StoredObject;
use FileVault\Lifecycle\Exception\LifecycleException;
use FileVault\Lifecycle\Exception\InvalidLifecycleOperationException;
use FileVault\Lifecycle\Exception\LifecycleConsistencyException;
use FileVault\Storage\Exception\StorageObjectNotFoundException;
use FileVault\Storage\Exception\StorageException;

class FileLifecycleTest extends IntegrationTestCase
{
    private $storageBackend;
    private $lifecycle;
    private $projectRepo;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->storageBackend = $this->createMock(StorageBackend::class);
        $this->lifecycle = new FileLifecycle($this->database, $this->storageBackend);
        $this->projectRepo = $this->database->createProjectRepository();
    }

    private function createProject(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $project = $this->projectRepo->create('Test', uniqid('test-'), 'active', 1000, 10, $now, $now);
        return $project->id;
    }

    public function testCreateSuccess(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $storedObj = new StoredObject($key, 123);

        $this->storageBackend->expects($this->once())
            ->method('store')
            ->with($projectId, $stream)
            ->willReturn($storedObj);

        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->assertGreaterThan(0, $record->id);
        $this->assertSame('active', $record->status);
        $this->assertSame($key, $record->storageKey);
    }

    public function testCreateStorageFailure(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');

        $this->storageBackend->expects($this->once())
            ->method('store')
            ->willThrowException(new StorageException('Storage error'));

        $this->expectException(LifecycleException::class);
        $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
    }

    public function testCreateDbFailureWithSuccessfulCompensation(): void
    {
        $invalidProjectId = 999999;
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $storedObj = new StoredObject($key, 123);

        $this->storageBackend->method('store')->willReturn($storedObj);

        $this->storageBackend->expects($this->once())->method('moveToTrash')->with($invalidProjectId, $key);
        $this->storageBackend->expects($this->once())->method('purge')->with($invalidProjectId, $key);

        $this->expectException(LifecycleException::class);
        $this->lifecycle->create($invalidProjectId, $stream, 'test.txt', 'text/plain', 'private');
    }

    public function testCreateDbFailureWithFailedCompensation(): void
    {
        $invalidProjectId = 999999;
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $storedObj = new StoredObject($key, 123);

        $this->storageBackend->method('store')->willReturn($storedObj);
        $this->storageBackend->method('moveToTrash')->willThrowException(new StorageException('Comp fail'));

        try {
            $this->lifecycle->create($invalidProjectId, $stream, 'test.txt', 'text/plain', 'private');
            $this->fail('Expected LifecycleConsistencyException');
        } catch (LifecycleConsistencyException $e) {
            $this->assertInstanceOf(\Exception::class, $e->getOriginalException());
            $this->assertInstanceOf(StorageException::class, $e->getCompensationException());
        }
    }

    public function testTrashSuccess(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');

        $this->storageBackend->expects($this->once())->method('moveToTrash')->with($projectId, $key);
        
        $this->lifecycle->trash($projectId, $record->id);
        
        $fileRepo = $this->database->createFileRepository();
        $trashedRecord = $fileRepo->findById($record->id);
        $this->assertSame('trashed', $trashedRecord->status);
    }

    public function testTrashInvalidState(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $this->storageBackend->method('store')->willReturn(new StoredObject(uniqid('key-'), 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);

        $this->expectException(InvalidLifecycleOperationException::class);
        $this->lifecycle->trash($projectId, $record->id);
    }

    public function testTrashDbFailureWithSuccessfulCompensation(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        
        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        
        $this->storageBackend->expects($this->once())->method('moveToTrash')->with($projectId, $key)
            ->willReturnCallback(function() use ($pdo, $record) {
                $pdo->exec("UPDATE files SET status = 'trashed' WHERE id = {$record->id}");
            });

        $this->storageBackend->expects($this->once())->method('restore')->with($projectId, $key);

        $this->expectException(LifecycleException::class);
        $this->lifecycle->trash($projectId, $record->id);
    }

    public function testTrashDbFailureWithFailedCompensation(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        
        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        
        $this->storageBackend->expects($this->once())->method('moveToTrash')->with($projectId, $key)
            ->willReturnCallback(function() use ($pdo, $record) {
                $pdo->exec("UPDATE files SET status = 'trashed' WHERE id = {$record->id}");
            });

        $this->storageBackend->expects($this->once())->method('restore')
            ->willThrowException(new StorageException('Comp fail'));

        try {
            $this->lifecycle->trash($projectId, $record->id);
            $this->fail('Expected LifecycleConsistencyException');
        } catch (LifecycleConsistencyException $e) {
            $this->assertInstanceOf(\Exception::class, $e->getOriginalException());
            $this->assertInstanceOf(StorageException::class, $e->getCompensationException());
        }
    }

    public function testRestoreSuccess(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);

        $this->storageBackend->expects($this->once())->method('restore')->with($projectId, $key);
        
        $this->lifecycle->restore($projectId, $record->id);
        
        $fileRepo = $this->database->createFileRepository();
        $restoredRecord = $fileRepo->findById($record->id);
        $this->assertSame('active', $restoredRecord->status);
    }

    public function testRestoreInvalidState(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $this->storageBackend->method('store')->willReturn(new StoredObject(uniqid('key-'), 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        
        $this->expectException(InvalidLifecycleOperationException::class);
        $this->lifecycle->restore($projectId, $record->id);
    }

    public function testRestoreDbFailureWithSuccessfulCompensation(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);
        
        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        
        $this->storageBackend->expects($this->once())->method('restore')->with($projectId, $key)
            ->willReturnCallback(function() use ($pdo, $record) {
                $pdo->exec("UPDATE files SET status = 'active' WHERE id = {$record->id}");
            });

        $this->storageBackend->expects($this->once())->method('moveToTrash')->with($projectId, $key);

        $this->expectException(LifecycleException::class);
        $this->lifecycle->restore($projectId, $record->id);
    }

    public function testRestoreDbFailureWithFailedCompensation(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);
        
        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        
        $this->storageBackend->expects($this->once())->method('restore')->with($projectId, $key)
            ->willReturnCallback(function() use ($pdo, $record) {
                $pdo->exec("UPDATE files SET status = 'active' WHERE id = {$record->id}");
            });

        $this->storageBackend->expects($this->once())->method('moveToTrash')
            ->willThrowException(new StorageException('Comp fail'));

        try {
            $this->lifecycle->restore($projectId, $record->id);
            $this->fail('Expected LifecycleConsistencyException');
        } catch (LifecycleConsistencyException $e) {
            $this->assertInstanceOf(\Exception::class, $e->getOriginalException());
            $this->assertInstanceOf(StorageException::class, $e->getCompensationException());
        }
    }

    public function testPurgeSuccess(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);

        $this->storageBackend->expects($this->once())->method('purge')->with($projectId, $key);
        
        $this->lifecycle->purge($projectId, $record->id);
        
        $fileRepo = $this->database->createFileRepository();
        $this->assertNull($fileRepo->findById($record->id));
    }

    public function testPurgeMissingPhysicalSuccess(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);

        $this->storageBackend->expects($this->once())
            ->method('purge')
            ->willThrowException(new StorageObjectNotFoundException());
        
        $this->lifecycle->purge($projectId, $record->id);
        
        $fileRepo = $this->database->createFileRepository();
        $this->assertNull($fileRepo->findById($record->id));
    }

    public function testPurgeInvalidState(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $this->storageBackend->method('store')->willReturn(new StoredObject(uniqid('key-'), 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        
        $this->expectException(InvalidLifecycleOperationException::class);
        $this->lifecycle->purge($projectId, $record->id);
    }

    public function testPurgeDbFailureAfterPhysicalPurge(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $key = uniqid('key-');
        $this->storageBackend->method('store')->willReturn(new StoredObject($key, 123));
        
        $record = $this->lifecycle->create($projectId, $stream, 'test.txt', 'text/plain', 'private');
        $this->lifecycle->trash($projectId, $record->id);

        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        
        $this->storageBackend->expects($this->once())->method('purge')->with($projectId, $key)
            ->willReturnCallback(function() use ($pdo, $record) {
                $pdo->exec("DELETE FROM files WHERE id = {$record->id}");
            });

        $this->expectException(LifecycleException::class);
        $this->lifecycle->purge($projectId, $record->id);
    }

    public function testReplaceSuccess(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $oldKey = uniqid('old-key-');
        $newKey = uniqid('new-key-');
        
        $this->storageBackend->expects($this->exactly(2))
            ->method('store')
            ->willReturnOnConsecutiveCalls(
                new StoredObject($oldKey, 123),
                new StoredObject($newKey, 456)
            );
        
        $oldRecord = $this->lifecycle->create($projectId, $stream, 'old.txt', 'text/plain', 'private');

        $this->storageBackend->expects($this->once())->method('moveToTrash')->with($projectId, $oldKey);
        
        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        $pdo->commit(); // Commit test wrapper transaction so Database::transaction can start one

        try {
            $newStream = fopen('php://memory', 'r');
            $newRecord = $this->lifecycle->replace($projectId, $oldRecord->id, $newStream, 'new.txt', 'text/plain', 'public');
            
            $this->assertGreaterThan(0, $newRecord->id);
            $this->assertNotEquals($oldRecord->id, $newRecord->id);
            $this->assertSame('active', $newRecord->status);
            
            $fileRepo = $this->database->createFileRepository();
            $oldRecordUpdated = $fileRepo->findById($oldRecord->id);
            $this->assertSame('trashed', $oldRecordUpdated->status);
        } finally {
            $pdo->beginTransaction(); // Restore for tearDown
        }
    }

    public function testReplaceOldTrashFailureWithCompensation(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $oldKey = uniqid('old-key-');
        $newKey = uniqid('new-key-');
        
        $this->storageBackend->expects($this->exactly(2))
            ->method('store')
            ->willReturnOnConsecutiveCalls(
                new StoredObject($oldKey, 123),
                new StoredObject($newKey, 456)
            );
        
        $oldRecord = $this->lifecycle->create($projectId, $stream, 'old.txt', 'text/plain', 'private');

        $this->storageBackend->expects($this->exactly(2))
            ->method('moveToTrash')
            ->willReturnCallback(function($pid, $key) use ($oldKey) {
                if ($key === $oldKey) {
                    throw new StorageException('fail');
                }
            });
            
        $this->storageBackend->expects($this->once())->method('purge')
            ->willThrowException(new StorageException('Comp fail'));

        $newStream = fopen('php://memory', 'r');
        
        try {
            $this->lifecycle->replace($projectId, $oldRecord->id, $newStream, 'new.txt', 'text/plain', 'public');
            $this->fail('Expected LifecycleConsistencyException');
        } catch (LifecycleConsistencyException $e) {
            $this->assertInstanceOf(\Exception::class, $e->getOriginalException());
            $this->assertInstanceOf(StorageException::class, $e->getCompensationException());
        }
    }

    public function testReplaceTransactionFailureWithCompensation(): void
    {
        $projectId = $this->createProject();
        $stream = fopen('php://memory', 'r');
        $oldKey = uniqid('old-key-');
        $newKey = uniqid('new-key-');
        
        $this->storageBackend->expects($this->exactly(2))
            ->method('store')
            ->willReturnOnConsecutiveCalls(
                new StoredObject($oldKey, 123),
                new StoredObject($newKey, 456)
            );
        
        $oldRecord = $this->lifecycle->create($projectId, $stream, 'old.txt', 'text/plain', 'private');

        $pdo = (new \ReflectionClass($this->database))->getProperty('pdo')->getValue($this->database);
        
        $this->storageBackend->expects($this->exactly(2))
            ->method('moveToTrash')
            ->willReturnCallback(function($pid, $key) use ($projectId, $oldKey, $newKey, $pdo) {
                if ($key === $oldKey) {
                    $pdo->exec('ALTER TABLE files RENAME TO files_hidden');
                }
                $this->assertSame($projectId, $pid);
                $this->assertTrue(in_array($key, [$oldKey, $newKey]));
            });
            
        $this->storageBackend->expects($this->once())->method('restore')->with($projectId, $oldKey);
        $this->storageBackend->expects($this->once())->method('purge')->with($projectId, $newKey)
            ->willThrowException(new StorageException('Comp fail'));
        
        $pdo->commit(); // Commit test wrapper transaction so Database::transaction can start one

        try {
            $newStream = fopen('php://memory', 'r');
            
            try {
                $this->lifecycle->replace($projectId, $oldRecord->id, $newStream, 'new.txt', 'text/plain', 'public');
                $this->fail('Expected LifecycleConsistencyException');
            } catch (LifecycleConsistencyException $e) {
                $this->assertInstanceOf(\Exception::class, $e->getOriginalException());
                $this->assertInstanceOf(StorageException::class, $e->getCompensationException());
            }
        } finally {
            $pdo->exec('ALTER TABLE files_hidden RENAME TO files');
            $pdo->beginTransaction(); // Restore for tearDown
        }
    }
}
