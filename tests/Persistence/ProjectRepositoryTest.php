<?php

declare(strict_types=1);

namespace FileVault\Tests\Persistence;

use FileVault\Persistence\Repository\ProjectRepository;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class ProjectRepositoryTest extends IntegrationTestCase
{
    public function testCanCreateAndFindProject(): void
    {
        $repo = $this->database->createProjectRepository();
        
        $createdAt = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));
        $updatedAt = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        $project = $repo->create(
            'Test Project',
            'test-project',
            'active',
            1000000,
            10000,
            $createdAt,
            $updatedAt
        );

        $this->assertGreaterThan(0, $project->id);
        $this->assertSame('Test Project', $project->name);
        $this->assertSame('test-project', $project->slug);

        $found = $repo->findById($project->id);
        $this->assertNotNull($found);
        $this->assertSame('Test Project', $found->name);
        
        $foundBySlug = $repo->findBySlug('test-project');
        $this->assertNotNull($foundBySlug);
        $this->assertSame($project->id, $foundBySlug->id);
    }

    public function testDuplicateSlugThrowsConstraintViolation(): void
    {
        $repo = $this->database->createProjectRepository();
        
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $repo->create('Project A', 'duplicate-slug', 'active', 100, 10, $now, $now);

        $this->expectException(ConstraintViolationException::class);
        $repo->create('Project B', 'duplicate-slug', 'active', 100, 10, $now, $now);
    }
}
