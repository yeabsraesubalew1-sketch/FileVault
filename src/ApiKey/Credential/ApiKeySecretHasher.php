<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

use FileVault\ApiKey\Exception\ApiKeyException;

final class ApiKeySecretHasher
{
    public function hash(#[\SensitiveParameter] string $rawSecretBytes): string
    {
        if (strlen($rawSecretBytes) !== 32) {
            throw new ApiKeyException('API key secret must be exactly 32 bytes.');
        }

        return hash('sha256', $rawSecretBytes, true);
    }
}
