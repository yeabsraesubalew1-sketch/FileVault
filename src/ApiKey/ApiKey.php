<?php

declare(strict_types=1);

namespace FileVault\ApiKey;

use FileVault\ApiKey\Exception\ApiKeyException;
use FileVault\ApiKey\Exception\InvalidApiKeyLifecycleOperationException;

final class ApiKey
{
    public function __construct(
        public readonly ?int $id,
        public readonly ApiKeyId $publicId,
        public readonly int $projectId,
        public readonly ApiKeyName $name,
        private ApiKeyStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $revokedAt = null
    ) {
        if ($this->projectId < 1) {
            throw new ApiKeyException('Project ID must be a positive integer.');
        }

        if ($this->status === ApiKeyStatus::ACTIVE && $this->revokedAt !== null) {
            throw new InvalidApiKeyLifecycleOperationException('Active API key cannot have a revocation timestamp.');
        }

        if ($this->status === ApiKeyStatus::REVOKED && $this->revokedAt === null) {
            throw new InvalidApiKeyLifecycleOperationException('Revoked API key must have a revocation timestamp.');
        }

        if ($this->revokedAt !== null && $this->revokedAt < $this->createdAt) {
            throw new InvalidApiKeyLifecycleOperationException('Revocation timestamp cannot be before creation timestamp.');
        }
    }

    public function getStatus(): ApiKeyStatus
    {
        return $this->status;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        if ($this->status === ApiKeyStatus::REVOKED) {
            return;
        }

        if ($now < $this->createdAt) {
            throw new InvalidApiKeyLifecycleOperationException('Cannot revoke with a timestamp before creation time.');
        }

        $this->status = ApiKeyStatus::REVOKED;
        $this->revokedAt = $now;
    }
}
