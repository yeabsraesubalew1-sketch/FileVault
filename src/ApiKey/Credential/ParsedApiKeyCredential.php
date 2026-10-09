<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

use FileVault\ApiKey\ApiKeyId;

final readonly class ParsedApiKeyCredential
{
    public function __construct(
        public ApiKeyId $publicId,
        #[\SensitiveParameter]
        public string $rawSecretBytes
    ) {
    }
}
