<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Credential;

use FileVault\ApiKey\Credential\ApiKeySecretHasher;
use PHPUnit\Framework\TestCase;

class ApiKeySecretHasherTest extends TestCase
{
    private ApiKeySecretHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new ApiKeySecretHasher();
    }

    public function testOutputIsExactly32Bytes(): void
    {
        $secret = random_bytes(32);
        $hash = $this->hasher->hash($secret);
        
        $this->assertSame(32, strlen($hash));
    }

    public function testSameRawSecretProducesSameDigest(): void
    {
        $secret = random_bytes(32);
        
        $hash1 = $this->hasher->hash($secret);
        $hash2 = $this->hasher->hash($secret);
        
        $this->assertSame($hash1, $hash2);
    }

    public function testDifferentSecretsProduceDifferentDigests(): void
    {
        $secret1 = random_bytes(32);
        $secret2 = random_bytes(32);
        
        $hash1 = $this->hasher->hash($secret1);
        $hash2 = $this->hasher->hash($secret2);
        
        $this->assertNotSame($hash1, $hash2);
    }

    public function testRejects31ByteSecret(): void
    {
        $this->expectException(\FileVault\ApiKey\Exception\ApiKeyException::class);
        $this->expectExceptionMessage('API key secret must be exactly 32 bytes.');
        
        $this->hasher->hash(random_bytes(31));
    }

    public function testRejects33ByteSecret(): void
    {
        $this->expectException(\FileVault\ApiKey\Exception\ApiKeyException::class);
        $this->expectExceptionMessage('API key secret must be exactly 32 bytes.');
        
        $this->hasher->hash(random_bytes(33));
    }
}
