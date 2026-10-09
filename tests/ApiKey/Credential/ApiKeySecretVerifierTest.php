<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Credential;

use FileVault\ApiKey\Credential\ApiKeySecretHasher;
use FileVault\ApiKey\Credential\ApiKeySecretVerifier;
use PHPUnit\Framework\TestCase;

class ApiKeySecretVerifierTest extends TestCase
{
    private ApiKeySecretHasher $hasher;
    private ApiKeySecretVerifier $verifier;

    protected function setUp(): void
    {
        $this->hasher = new ApiKeySecretHasher();
        $this->verifier = new ApiKeySecretVerifier($this->hasher);
    }

    public function testCorrectSecretVerifies(): void
    {
        $secret = random_bytes(32);
        $hash = $this->hasher->hash($secret);

        $this->assertTrue($this->verifier->verify($secret, $hash));
    }

    public function testIncorrectSecretFails(): void
    {
        $secret1 = random_bytes(32);
        $secret2 = random_bytes(32);
        $hash = $this->hasher->hash($secret1);

        $this->assertFalse($this->verifier->verify($secret2, $hash));
    }

    public function testMalformedStoredHashFails(): void
    {
        $secret = random_bytes(32);
        $this->assertFalse($this->verifier->verify($secret, 'invalid-hash-that-is-not-32-bytes'));
    }

    public function testHashLength31Fails(): void
    {
        $secret = random_bytes(32);
        $hash31 = random_bytes(31);
        $this->assertFalse($this->verifier->verify($secret, $hash31));
    }

    public function testHashLength33Fails(): void
    {
        $secret = random_bytes(32);
        $hash33 = random_bytes(33);
        $this->assertFalse($this->verifier->verify($secret, $hash33));
    }
}
