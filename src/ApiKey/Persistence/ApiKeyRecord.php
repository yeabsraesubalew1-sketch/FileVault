<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Persistence;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Exception\ApiKeyException;
use FileVault\ApiKey\Exception\InvalidApiKeyLifecycleOperationException;

final readonly class ApiKeyRecord
{
    public function __construct(
        public ?int $id,
        public ApiKeyId $publicId,
        public int $projectId,
        public ApiKeyName $name,
        public string $secretHash,
        public ApiKeyStatus $status,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $revokedAt = null
    ) {
        if ($this->id !== null && $this->id < 1) {
            throw new ApiKeyException('Internal database ID must be a positive integer.');
        }

        if ($this->projectId < 1) {
            throw new ApiKeyException('Project ID must be a positive integer.');
        }

        if (strlen($this->secretHash) !== 32) {
            throw new ApiKeyException('API key secret hash must be exactly 32 bytes.');
        }

        if ($this->status === ApiKeyStatus::ACTIVE && $this->revokedAt !== null) {
            throw new InvalidApiKeyLifecycleOperationException('Active API key record cannot have a revocation timestamp.');
        }

        if ($this->status === ApiKeyStatus::REVOKED && $this->revokedAt === null) {
            throw new InvalidApiKeyLifecycleOperationException('Revoked API key record must have a revocation timestamp.');
        }

        if ($this->revokedAt !== null && $this->revokedAt < $this->createdAt) {
            throw new InvalidApiKeyLifecycleOperationException('Revocation timestamp cannot be before creation timestamp.');
        }
    }
}
