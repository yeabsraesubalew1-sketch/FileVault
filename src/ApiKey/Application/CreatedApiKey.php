<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Application;

use FileVault\ApiKey\ApiKey;


final readonly class CreatedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        #[\SensitiveParameter]
        public string $plaintextCredential
    ) {
    }
}
