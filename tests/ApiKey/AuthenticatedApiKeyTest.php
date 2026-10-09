<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\AuthenticatedApiKey;
use PHPUnit\Framework\TestCase;

class AuthenticatedApiKeyTest extends TestCase
{
    public function testContainsOnlyIntendedIdentity(): void
    {
        $publicId = new ApiKeyId(random_bytes(16));
        $projectId = 42;
        $name = new ApiKeyName('Auth Key');

        $authenticatedKey = new AuthenticatedApiKey($publicId, $projectId, $name);

        $this->assertSame($publicId, $authenticatedKey->publicId);
        $this->assertSame($projectId, $authenticatedKey->projectId);
        $this->assertSame($name, $authenticatedKey->name);
    }
}
