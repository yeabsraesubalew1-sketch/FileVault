<?php

declare(strict_types=1);

namespace FileVault\Tests\Project;

use PHPUnit\Framework\TestCase;
use FileVault\Project\Project;
use FileVault\Project\ProjectStatus;
use FileVault\Project\ProjectQuota;
use FileVault\Project\ProjectUsage;
use FileVault\Project\Exception\InvalidStatusTransitionException;

final class ProjectTest extends TestCase
{
    private function createProject(ProjectStatus $status = ProjectStatus::ACTIVE): Project
    {
        return new Project(
            1,
            'Test Project',
            $status,
            new ProjectQuota(0, 0, 0),
            new ProjectUsage(0, 0),
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );
    }

    public function testActiveToRestricted(): void
    {
        $project = $this->createProject(ProjectStatus::ACTIVE);
        $project->restrict(new \DateTimeImmutable());
        $this->assertEquals(ProjectStatus::RESTRICTED, $project->getStatus());
    }

    public function testActiveToDisabled(): void
    {
        $project = $this->createProject(ProjectStatus::ACTIVE);
        $project->disable(new \DateTimeImmutable());
        $this->assertEquals(ProjectStatus::DISABLED, $project->getStatus());
    }

    public function testRestrictedToActive(): void
    {
        $project = $this->createProject(ProjectStatus::RESTRICTED);
        $project->activate(new \DateTimeImmutable());
        $this->assertEquals(ProjectStatus::ACTIVE, $project->getStatus());
    }

    public function testRestrictedToDisabled(): void
    {
        $project = $this->createProject(ProjectStatus::RESTRICTED);
        $project->disable(new \DateTimeImmutable());
        $this->assertEquals(ProjectStatus::DISABLED, $project->getStatus());
    }

    public function testDisabledToActive(): void
    {
        $project = $this->createProject(ProjectStatus::DISABLED);
        $project->activate(new \DateTimeImmutable());
        $this->assertEquals(ProjectStatus::ACTIVE, $project->getStatus());
    }

    public function testDisabledToRestrictedIsInvalid(): void
    {
        $project = $this->createProject(ProjectStatus::DISABLED);
        $this->expectException(InvalidStatusTransitionException::class);
        $project->restrict(new \DateTimeImmutable());
    }

    public function testSameStateIdempotency(): void
    {
        $project = $this->createProject(ProjectStatus::ACTIVE);
        $updatedAt = clone $project->getUpdatedAt();
        $project->activate(new \DateTimeImmutable());
        $this->assertEquals(ProjectStatus::ACTIVE, $project->getStatus());
        $this->assertEquals($updatedAt, $project->getUpdatedAt());
    }

    public function testIsOperational(): void
    {
        $this->assertTrue($this->createProject(ProjectStatus::ACTIVE)->isOperational());
        $this->assertFalse($this->createProject(ProjectStatus::RESTRICTED)->isOperational());
        $this->assertFalse($this->createProject(ProjectStatus::DISABLED)->isOperational());
    }

    public function testAllowsPublicDelivery(): void
    {
        $this->assertTrue($this->createProject(ProjectStatus::ACTIVE)->allowsPublicDelivery());
        $this->assertTrue($this->createProject(ProjectStatus::RESTRICTED)->allowsPublicDelivery());
        $this->assertFalse($this->createProject(ProjectStatus::DISABLED)->allowsPublicDelivery());
    }

    public function testLoweringQuotaBelowUsageAllowed(): void
    {
        // Initial usage: file count = 10, storage = 1000
        $project = new Project(
            1,
            'Test Project',
            ProjectStatus::ACTIVE,
            new ProjectQuota(0, 100, 2000), // Initial quota: file count = 100, storage = 2000
            new ProjectUsage(10, 1000),
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        // Update quota to: file count = 5, storage = 500
        $newQuota = new ProjectQuota(0, 5, 500);
        $project->updateQuota($newQuota, new \DateTimeImmutable());

        // The quota update must succeed.
        $this->assertEquals(5, $project->getQuota()->maxFileCount);
        $this->assertEquals(500, $project->getQuota()->maxStorageBytes);

        // Usage remains: file count = 10, storage = 1000
        $this->assertEquals(10, $project->getUsage()->currentFileCount);
        $this->assertEquals(1000, $project->getUsage()->currentStorageBytes);

        // Usage-increasing operation rejected
        $deltaIncrease = \FileVault\Project\UsageDelta::newFile(100);
        $resultIncrease = $project->getQuota()->fits($project->getUsage(), $deltaIncrease, 100);
        $this->assertFalse($resultIncrease->allowed);

        // Zero usage delta allowed
        $deltaZero = \FileVault\Project\UsageDelta::trash();
        $resultZero = $project->getQuota()->fits($project->getUsage(), $deltaZero, 0);
        $this->assertTrue($resultZero->allowed);

        // Usage-reducing operation allowed
        $deltaReduce = \FileVault\Project\UsageDelta::purge(100);
        $resultReduce = $project->getQuota()->fits($project->getUsage(), $deltaReduce, 0);
        $this->assertTrue($resultReduce->allowed);
    }
}
