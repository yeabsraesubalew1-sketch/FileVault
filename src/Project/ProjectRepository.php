<?php

declare(strict_types=1);

namespace FileVault\Project;

interface ProjectRepository
{
    public function findById(int $id): ?Project;
    
    public function findByIdForUpdate(int $id): ?Project;
    
    public function findByName(string $name): ?Project;
    
    public function save(Project $project): Project;
}
