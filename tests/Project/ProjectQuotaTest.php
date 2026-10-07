<?php

declare(strict_types=1);

namespace FileVault\Tests\Project;

use PHPUnit\Framework\TestCase;
use FileVault\Project\ProjectQuota;
use FileVault\Project\ProjectUsage;
use FileVault\Project\UsageDelta;
use FileVault\Project\Exception\InvalidQuotaException;

final class ProjectQuotaTest extends TestCase
{
    public function testNegativeFileSizeRejected(): void
    {
        $this->expectException(InvalidQuotaException::class);
        new ProjectQuota(-1, 0, 0);
    }

    public function testNegativeFileCountRejected(): void
    {
        $this->expectException(InvalidQuotaException::class);
        new ProjectQuota(0, -1, 0);
    }

    public function testNegativeStorageRejected(): void
    {
        $this->expectException(InvalidQuotaException::class);
        new ProjectQuota(0, 0, -1);
    }

    public function testUnlimitedQuota(): void
    {
        $quota = new ProjectQuota(0, 0, 0);
        $usage = new ProjectUsage(0, 0);
        
        $delta = UsageDelta::newFile(10000000);
        $result = $quota->fits($usage, $delta, 10000000);
        $this->assertTrue($result->allowed);
        $this->assertEmpty($result->violations);
    }

    public function testExceedsFileSize(): void
    {
        $quota = new ProjectQuota(100, 0, 0);
        $usage = new ProjectUsage(0, 0);
        
        $delta = UsageDelta::newFile(101);
        $result = $quota->fits($usage, $delta, 101);
        
        $this->assertFalse($result->allowed);
        $this->assertContains(ProjectQuota::VIOLATION_FILE_SIZE, $result->violations);
    }

    public function testExceedsFileCount(): void
    {
        $quota = new ProjectQuota(0, 5, 0);
        $usage = new ProjectUsage(5, 0);
        
        $delta = UsageDelta::newFile(100);
        $result = $quota->fits($usage, $delta, 100);
        
        $this->assertFalse($result->allowed);
        $this->assertContains(ProjectQuota::VIOLATION_FILE_COUNT, $result->violations);
    }

    public function testExceedsStorage(): void
    {
        $quota = new ProjectQuota(0, 0, 500);
        $usage = new ProjectUsage(0, 400);
        
        $delta = UsageDelta::newFile(101);
        $result = $quota->fits($usage, $delta, 101);
        
        $this->assertFalse($result->allowed);
        $this->assertContains(ProjectQuota::VIOLATION_STORAGE, $result->violations);
    }

    public function testExactBoundary(): void
    {
        $quota = new ProjectQuota(100, 5, 500);
        $usage = new ProjectUsage(4, 400);
        
        $delta = UsageDelta::newFile(100);
        $result = $quota->fits($usage, $delta, 100);
        
        $this->assertTrue($result->allowed);
    }

    public function testMultipleViolations(): void
    {
        $quota = new ProjectQuota(50, 5, 500);
        $usage = new ProjectUsage(5, 500);
        
        $delta = UsageDelta::newFile(100);
        $result = $quota->fits($usage, $delta, 100);
        
        $this->assertFalse($result->allowed);
        $this->assertCount(3, $result->violations);
        $this->assertContains(ProjectQuota::VIOLATION_FILE_SIZE, $result->violations);
        $this->assertContains(ProjectQuota::VIOLATION_FILE_COUNT, $result->violations);
        $this->assertContains(ProjectQuota::VIOLATION_STORAGE, $result->violations);
    }

    public function testReplacementDeltaSemantics(): void
    {
        // Replacement counts the new file toward file count and adds the new file's size to storage.
        // The old file remains counted until purged.
        // fileCountDelta = +1, storageBytesDelta = +newFileSize
        
        $delta = UsageDelta::replace(150);
        $this->assertEquals(1, $delta->fileCountDelta);
        $this->assertEquals(150, $delta->storageBytesDelta);
    }
}
