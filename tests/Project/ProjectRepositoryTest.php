<?php

declare(strict_types=1);

namespace FileVault\Tests\Project;

use FileVault\Tests\Persistence\IntegrationTestCase;
use FileVault\Project\Project;
use FileVault\Project\ProjectStatus;
use FileVault\Project\ProjectQuota;
use FileVault\Project\ProjectUsage;
use FileVault\Persistence\Repository\ProjectRepository;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class ProjectRepositoryTest extends IntegrationTestCase
{
    private ProjectRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->repository = $this->database->createProjectRepository();
    }
    
    private function getUniqueProjectName(): string
    {
        return 'Test Project ' . bin2hex(random_bytes(8));
    }

    public function testSaveAndLoad(): void
    {
        $name = $this->getUniqueProjectName();
        $project = new Project(
            0,
            $name,
            ProjectStatus::ACTIVE,
            new ProjectQuota(100, 100, 1000),
            new ProjectUsage(10, 50),
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );

        $savedProject = $this->repository->save($project);
        $this->assertGreaterThan(0, $savedProject->id);

        $loadedProject = $this->repository->findById($savedProject->id);
        $this->assertNotNull($loadedProject);
        $this->assertEquals($name, $loadedProject->name);
        $this->assertEquals(ProjectStatus::ACTIVE, $loadedProject->getStatus());
        $this->assertEquals(100, $loadedProject->getQuota()->maxFileSize);
        $this->assertEquals(10, $loadedProject->getUsage()->currentFileCount);
    }

    public function testLookupByName(): void
    {
        $name = $this->getUniqueProjectName();
        $project = new Project(0, $name, ProjectStatus::ACTIVE, new ProjectQuota(0, 0, 0), new ProjectUsage(0, 0), new \DateTimeImmutable(), new \DateTimeImmutable());
        $savedProject = $this->repository->save($project);

        $loadedProject = $this->repository->findByName($name);
        $this->assertNotNull($loadedProject);
        $this->assertEquals($savedProject->id, $loadedProject->id);
    }

    public function testCaseInsensitiveDuplicateNameRejection(): void
    {
        $name = $this->getUniqueProjectName();
        $project1 = new Project(0, $name, ProjectStatus::ACTIVE, new ProjectQuota(0, 0, 0), new ProjectUsage(0, 0), new \DateTimeImmutable(), new \DateTimeImmutable());
        $this->repository->save($project1);

        $project2 = new Project(0, strtoupper($name), ProjectStatus::ACTIVE, new ProjectQuota(0, 0, 0), new ProjectUsage(0, 0), new \DateTimeImmutable(), new \DateTimeImmutable());
        
        $this->expectException(ConstraintViolationException::class);
        $this->repository->save($project2);
    }

    public function testFindByIdForUpdateInTransaction(): void
    {
        $name = $this->getUniqueProjectName();
        $project = new Project(0, $name, ProjectStatus::ACTIVE, new ProjectQuota(0, 0, 0), new ProjectUsage(0, 0), new \DateTimeImmutable(), new \DateTimeImmutable());
        $savedProject = $this->repository->save($project);

        $reflection = new \ReflectionClass($this->database);
        $pdo = $reflection->getProperty('pdo')->getValue($this->database);
        $this->assertTrue($pdo->inTransaction());

        $lockedProject = $this->repository->findByIdForUpdate($savedProject->id);
        $this->assertNotNull($lockedProject);
        $this->assertEquals($savedProject->id, $lockedProject->id);
    }
}
