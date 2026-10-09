<?php

declare(strict_types=1);

namespace FileVault\ApiKey;

enum ApiKeyStatus: string
{
    case ACTIVE = 'active';
    case REVOKED = 'revoked';
}
