<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

final class ApiKeySecretVerifier
{
    public function __construct(
        private ApiKeySecretHasher $hasher
    ) {}

    public function verify(#[\SensitiveParameter] string $rawSecretBytes, string $storedHash): bool
    {
        if (strlen($storedHash) !== 32) {
            return false;
        }

        $computedHash = $this->hasher->hash($rawSecretBytes);

        return hash_equals($storedHash, $computedHash);
    }
}
