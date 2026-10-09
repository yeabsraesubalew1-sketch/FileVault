<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Application;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Application\AuthenticateApiKey;
use FileVault\ApiKey\Credential\ApiKeyCredentialParser;
use FileVault\ApiKey\Credential\ApiKeyGenerator;
use FileVault\ApiKey\Credential\ApiKeySecretHasher;
use FileVault\ApiKey\Credential\ApiKeySecretVerifier;
use FileVault\ApiKey\Exception\ApiKeyAuthenticationException;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\ApiKey\Persistence\ApiKeyRepository;
use PHPUnit\Framework\TestCase;

class AuthenticateApiKeyTest extends TestCase
{
    private ApiKeyGenerator $generator;
    private ApiKeySecretHasher $hasher;
    private ApiKeyCredentialParser $parser;
    private ApiKeySecretVerifier $verifier;

    protected function setUp(): void
    {
        $this->generator = new ApiKeyGenerator();
        $this->hasher = new ApiKeySecretHasher();
        $this->parser = new ApiKeyCredentialParser();
        $this->verifier = new ApiKeySecretVerifier($this->hasher);
    }

    public function testValidCredentialAuthenticatesSuccessfully(): void
    {
        $credentialResult = $this->generator->generate();
        $secretHash = $this->hasher->hash($credentialResult->rawSecretBytes);

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(new ApiKeyRecord(
            id: 1,
            publicId: $credentialResult->publicId,
            projectId: 42,
            name: new ApiKeyName('App Key'),
            secretHash: $secretHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        ));

        $authenticator = new AuthenticateApiKey($this->parser, $this->verifier, $repository);
        $authenticated = $authenticator->authenticate($credentialResult->plaintextCredential);

        $this->assertSame($credentialResult->publicId->bytes, $authenticated->publicId->bytes);
        $this->assertSame(42, $authenticated->projectId);
        $this->assertSame('App Key', $authenticated->name->value);
    }

    public function testMalformedCredentialThrowsGenericAuthenticationException(): void
    {
        $repository = $this->createMock(ApiKeyRepository::class);
        $authenticator = new AuthenticateApiKey($this->parser, $this->verifier, $repository);

        try {
            $authenticator->authenticate('invalid-credential');
            $this->fail('Expected exception');
        } catch (ApiKeyAuthenticationException $e) {
            $this->assertSame('Invalid API key credential.', $e->getMessage());
        }
    }

    public function testUnknownPublicIdThrowsGenericAuthenticationException(): void
    {
        $credentialResult = $this->generator->generate();

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(null);

        $authenticator = new AuthenticateApiKey($this->parser, $this->verifier, $repository);

        try {
            $authenticator->authenticate($credentialResult->plaintextCredential);
            $this->fail('Expected exception');
        } catch (ApiKeyAuthenticationException $e) {
            $this->assertSame('Invalid API key credential.', $e->getMessage());
        }
    }

    public function testRevokedKeyThrowsGenericAuthenticationException(): void
    {
        $credentialResult = $this->generator->generate();
        $secretHash = $this->hasher->hash($credentialResult->rawSecretBytes);

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(new ApiKeyRecord(
            id: 1,
            publicId: $credentialResult->publicId,
            projectId: 42,
            name: new ApiKeyName('App Key'),
            secretHash: $secretHash,
            status: ApiKeyStatus::REVOKED,
            createdAt: new \DateTimeImmutable('-1 day'),
            revokedAt: new \DateTimeImmutable()
        ));

        $authenticator = new AuthenticateApiKey($this->parser, $this->verifier, $repository);

        try {
            $authenticator->authenticate($credentialResult->plaintextCredential);
            $this->fail('Expected exception');
        } catch (ApiKeyAuthenticationException $e) {
            $this->assertSame('Invalid API key credential.', $e->getMessage());
        }
    }

    public function testIncorrectSecretThrowsGenericAuthenticationException(): void
    {
        $credentialResult = $this->generator->generate();
        $wrongHash = $this->hasher->hash(random_bytes(32));

        $repository = $this->createMock(ApiKeyRepository::class);
        $repository->method('findByPublicId')->willReturn(new ApiKeyRecord(
            id: 1,
            publicId: $credentialResult->publicId,
            projectId: 42,
            name: new ApiKeyName('App Key'),
            secretHash: $wrongHash,
            status: ApiKeyStatus::ACTIVE,
            createdAt: new \DateTimeImmutable()
        ));

        $authenticator = new AuthenticateApiKey($this->parser, $this->verifier, $repository);

        try {
            $authenticator->authenticate($credentialResult->plaintextCredential);
            $this->fail('Expected exception');
        } catch (ApiKeyAuthenticationException $e) {
            $this->assertSame('Invalid API key credential.', $e->getMessage());
        }
    }
}
