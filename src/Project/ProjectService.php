<?php

declare(strict_types=1);

namespace FileVault\Project;

use FileVault\Project\Exception\DuplicateProjectNameException;
use FileVault\Project\Exception\InvalidProjectNameException;
use FileVault\Project\Exception\ProjectNotFoundException;
use FileVault\Persistence\Exception\ConstraintViolationException;

final class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $repository
    ) {}

    public function create(
        string $name,
        ProjectQuota $quota,
        \DateTimeImmutable $now
    ): Project {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidProjectNameException('Invalid project name.');
        }

        $project = new Project(
            id: 0,
            name: $name,
            status: ProjectStatus::ACTIVE,
            quota: $quota,
            usage: new ProjectUsage(0, 0),
            createdAt: $now,
            updatedAt: $now
        );

        try {
            return $this->repository->save($project);
        } catch (ConstraintViolationException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof \PDOException && str_contains($previous->getMessage(), 'uq_projects_name')) {
                throw new DuplicateProjectNameException('Project name already exists.', 0, $e);
            }
            throw $e;
        }
    }

    public function get(int $id): Project
    {
        $project = $this->repository->findById($id);
        if ($project === null) {
            throw new ProjectNotFoundException('Project not found.');
        }

        return $project;
    }

    public function activate(int $id, \DateTimeImmutable $now): void
    {
        $project = $this->repository->findByIdForUpdate($id);
        if ($project === null) {
            throw new ProjectNotFoundException('Project not found.');
        }

        $project->activate($now);
        $this->repository->save($project);
    }

    public function restrict(int $id, \DateTimeImmutable $now): void
    {
        $project = $this->repository->findByIdForUpdate($id);
        if ($project === null) {
            throw new ProjectNotFoundException('Project not found.');
        }

        $project->restrict($now);
        $this->repository->save($project);
    }

    public function disable(int $id, \DateTimeImmutable $now): void
    {
        $project = $this->repository->findByIdForUpdate($id);
        if ($project === null) {
            throw new ProjectNotFoundException('Project not found.');
        }

        $project->disable($now);
        $this->repository->save($project);
    }

    public function updateQuota(int $id, ProjectQuota $newQuota, \DateTimeImmutable $now): void
    {
        $project = $this->repository->findByIdForUpdate($id);
        if ($project === null) {
            throw new ProjectNotFoundException('Project not found.');
        }

        $project->updateQuota($newQuota, $now);
        $this->repository->save($project);
    }
}
