<?php

declare(strict_types=1);

namespace FileVault\Tests\Project;

use PHPUnit\Framework\TestCase;
use FileVault\Project\ProjectUsage;
use FileVault\Project\UsageDelta;
use FileVault\Project\Exception\InvalidUsageException;

final class ProjectUsageTest extends TestCase
{
    public function testPositiveDeltas(): void
    {
        $usage = new ProjectUsage(0, 0);
        $newUsage = $usage->applyDelta(UsageDelta::newFile(100));
        
        $this->assertEquals(1, $newUsage->currentFileCount);
        $this->assertEquals(100, $newUsage->currentStorageBytes);
    }

    public function testZeroDeltas(): void
    {
        $usage = new ProjectUsage(5, 500);
        $newUsage = $usage->applyDelta(UsageDelta::trash());
        
        $this->assertEquals(5, $newUsage->currentFileCount);
        $this->assertEquals(500, $newUsage->currentStorageBytes);
        
        $newUsage2 = $usage->applyDelta(UsageDelta::restore());
        $this->assertEquals(5, $newUsage2->currentFileCount);
        $this->assertEquals(500, $newUsage2->currentStorageBytes);
    }

    public function testNegativeDeltas(): void
    {
        $usage = new ProjectUsage(5, 500);
        $newUsage = $usage->applyDelta(UsageDelta::purge(100));
        
        $this->assertEquals(4, $newUsage->currentFileCount);
        $this->assertEquals(400, $newUsage->currentStorageBytes);
    }

    public function testNegativeUsageRejectionFileCount(): void
    {
        $usage = new ProjectUsage(0, 500);
        $this->expectException(InvalidUsageException::class);
        $usage->applyDelta(UsageDelta::purge(100));
    }

    public function testNegativeUsageRejectionStorage(): void
    {
        $usage = new ProjectUsage(1, 50);
        $this->expectException(InvalidUsageException::class);
        $usage->applyDelta(UsageDelta::purge(100));
    }

    public function testReplacementAccounting(): void
    {
        $usage = new ProjectUsage(1, 10);
        $newUsage = $usage->applyDelta(UsageDelta::replace(15));
        
        $this->assertEquals(2, $newUsage->currentFileCount);
        $this->assertEquals(25, $newUsage->currentStorageBytes);
    }
}
