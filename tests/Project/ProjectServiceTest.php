<?php

declare(strict_types=1);

namespace FileVault\Tests\Project;

use FileVault\Tests\Persistence\IntegrationTestCase;
use FileVault\Project\ProjectService;
use FileVault\Project\ProjectStatus;
use FileVault\Project\ProjectQuota;
use FileVault\Project\Exception\InvalidProjectNameException;
use FileVault\Project\Exception\ProjectNotFoundException;

final class ProjectServiceTest extends IntegrationTestCase
{
    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new ProjectService(
            $this->database->createProjectRepository()
        );
    }

    private function getUniqueProjectName(): string
    {
        return 'Test Project ' . bin2hex(random_bytes(8));
    }

    public function testCreateProject(): void
    {
        $name = $this->getUniqueProjectName();
        $quota = new ProjectQuota(100, 100, 100);
        
        $project = $this->service->create($name, $quota, new \DateTimeImmutable());
        
        $this->assertGreaterThan(0, $project->id);
        $this->assertEquals($name, $project->name);
        $this->assertEquals(ProjectStatus::ACTIVE, $project->getStatus());
    }

    public function testCreateProjectTrimsName(): void
    {
        $name = $this->getUniqueProjectName();
        $quota = new ProjectQuota(100, 100, 100);
        
        $project = $this->service->create("  $name  ", $quota, new \DateTimeImmutable());
        
        $this->assertEquals($name, $project->name);
    }

    public function testCreateProjectRejectsEmptyName(): void
    {
        $this->expectException(InvalidProjectNameException::class);
        $this->service->create('   ', new ProjectQuota(0, 0, 0), new \DateTimeImmutable());
    }

    public function testCreateDuplicateProjectName(): void
    {
        $name = $this->getUniqueProjectName();
        $this->service->create($name, new ProjectQuota(0, 0, 0), new \DateTimeImmutable());
        
        $this->expectException(\FileVault\Project\Exception\DuplicateProjectNameException::class);
        $this->service->create($name, new ProjectQuota(0, 0, 0), new \DateTimeImmutable());
    }

    public function testGetProject(): void
    {
        $project = $this->service->create($this->getUniqueProjectName(), new ProjectQuota(0, 0, 0), new \DateTimeImmutable());
        
        $loaded = $this->service->get($project->id);
        $this->assertEquals($project->id, $loaded->id);
    }

    public function testGetNonExistentProject(): void
    {
        $this->expectException(ProjectNotFoundException::class);
        $this->service->get(999999);
    }

    public function testActivateRestrictDisable(): void
    {
        $project = $this->service->create($this->getUniqueProjectName(), new ProjectQuota(0, 0, 0), new \DateTimeImmutable());
        
        $this->service->restrict($project->id, new \DateTimeImmutable());
        $restricted = $this->service->get($project->id);
        $this->assertEquals(ProjectStatus::RESTRICTED, $restricted->getStatus());

        $this->service->disable($project->id, new \DateTimeImmutable());
        $disabled = $this->service->get($project->id);
        $this->assertEquals(ProjectStatus::DISABLED, $disabled->getStatus());

        $this->service->activate($project->id, new \DateTimeImmutable());
        $activated = $this->service->get($project->id);
        $this->assertEquals(ProjectStatus::ACTIVE, $activated->getStatus());
    }

    public function testUpdateQuota(): void
    {
        $project = $this->service->create($this->getUniqueProjectName(), new ProjectQuota(0, 0, 0), new \DateTimeImmutable());
        
        $this->service->updateQuota($project->id, new ProjectQuota(5, 5, 5), new \DateTimeImmutable());
        
        $updated = $this->service->get($project->id);
        $this->assertEquals(5, $updated->getQuota()->maxFileSize);
        $this->assertEquals(5, $updated->getQuota()->maxFileCount);
        $this->assertEquals(5, $updated->getQuota()->maxStorageBytes);
    }
}
