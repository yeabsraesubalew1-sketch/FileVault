<?php

declare(strict_types=1);

namespace FileVault\Project;

enum ProjectStatus: string
{
    case ACTIVE = 'active';
    case RESTRICTED = 'restricted';
    case DISABLED = 'disabled';
}
