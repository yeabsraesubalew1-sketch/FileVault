<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Application;

use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\AuthenticatedApiKey;
use FileVault\ApiKey\Credential\ApiKeyCredentialParser;
use FileVault\ApiKey\Credential\ApiKeySecretVerifier;
use FileVault\ApiKey\Exception\ApiKeyAuthenticationException;
use FileVault\ApiKey\Exception\MalformedApiKeyCredentialException;
use FileVault\ApiKey\Persistence\ApiKeyRepository;

final class AuthenticateApiKey
{
    public function __construct(
        private ApiKeyCredentialParser $parser,
        private ApiKeySecretVerifier $verifier,
        private ApiKeyRepository $repository
    ) {}

    public function authenticate(#[\SensitiveParameter] string $plaintextCredential): AuthenticatedApiKey
    {
        try {
            $parsed = $this->parser->parse($plaintextCredential);
        } catch (MalformedApiKeyCredentialException $e) {
            throw new ApiKeyAuthenticationException('Invalid API key credential.');
        }

        $record = $this->repository->findByPublicId($parsed->publicId);
        
        $dummyHash = hash('sha256', 'dummy', true); // 32 bytes
        $storedHash = $record !== null ? $record->secretHash : $dummyHash;
        
        $verified = $this->verifier->verify($parsed->rawSecretBytes, $storedHash);
        
        if ($record === null || $record->status === ApiKeyStatus::REVOKED || !$verified) {
            throw new ApiKeyAuthenticationException('Invalid API key credential.');
        }

        return new AuthenticatedApiKey($parsed->publicId, $record->projectId, $record->name);
    }
}
