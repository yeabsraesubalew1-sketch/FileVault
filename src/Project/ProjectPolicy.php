<?php

declare(strict_types=1);

namespace FileVault\Project;

use FileVault\Project\Exception\ProjectNotOperationalException;
use FileVault\Project\Exception\PublicDeliveryNotAllowedException;

final class ProjectPolicy
{
    public function requireOperational(Project $project): void
    {
        if (!$project->isOperational()) {
            throw new ProjectNotOperationalException('Project is not operational.');
        }
    }

    public function requirePublicDeliveryAllowed(Project $project): void
    {
        if (!$project->allowsPublicDelivery()) {
            throw new PublicDeliveryNotAllowedException('Project does not allow public delivery.');
        }
    }
}
