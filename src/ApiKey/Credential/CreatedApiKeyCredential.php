<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

use FileVault\ApiKey\ApiKeyId;

final readonly class CreatedApiKeyCredential
{
    public function __construct(
        public ApiKeyId $publicId,
        #[\SensitiveParameter]
        public string $plaintextCredential,
        #[\SensitiveParameter]
        public string $rawSecretBytes
    ) {
    }
}
