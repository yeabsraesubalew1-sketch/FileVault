<?php

declare(strict_types=1);

namespace FileVault\ApiKey;

final readonly class AuthenticatedApiKey
{
    public function __construct(
        public ApiKeyId $publicId,
        public int $projectId,
        public ApiKeyName $name
    ) {
    }
}
