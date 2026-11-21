<?php

declare(strict_types=1);

namespace FileVault\Project;

interface ProjectRepository
{
    public function findById(int $id): ?Project;
    
    public function findByIdForUpdate(int $id): ?Project;
    
    public function findByName(string $name): ?Project;
    
    /**
     * @return list<Project>
     */
    public function findAll(int $limit = 50, int $offset = 0): array;

    public function save(Project $project): Project;
}
