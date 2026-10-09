<?php

declare(strict_types=1);

namespace FileVault\ApiKey;

use FileVault\ApiKey\Exception\ApiKeyException;

final readonly class ApiKeyId
{
    public function __construct(
        public string $bytes
    ) {
        if (strlen($bytes) !== 16) {
            throw new ApiKeyException('ApiKeyId must be exactly 16 bytes.');
        }
    }
}
