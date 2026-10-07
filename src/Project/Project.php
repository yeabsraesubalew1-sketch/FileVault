<?php

declare(strict_types=1);

namespace FileVault\Project;

use FileVault\Project\Exception\InvalidStatusTransitionException;
use FileVault\Project\Exception\QuotaExceededException;

final class Project
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        private ProjectStatus $status,
        private ProjectQuota $quota,
        private ProjectUsage $usage,
        public readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt
    ) {}

    public function getStatus(): ProjectStatus
    {
        return $this->status;
    }

    public function getQuota(): ProjectQuota
    {
        return $this->quota;
    }

    public function getUsage(): ProjectUsage
    {
        return $this->usage;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        if ($this->status !== ProjectStatus::ACTIVE) {
            $this->status = ProjectStatus::ACTIVE;
            $this->updatedAt = $now;
        }
    }

    public function restrict(\DateTimeImmutable $now): void
    {
        if ($this->status === ProjectStatus::DISABLED) {
            throw new InvalidStatusTransitionException('Cannot transition from disabled to restricted.');
        }

        if ($this->status === ProjectStatus::ACTIVE) {
            $this->status = ProjectStatus::RESTRICTED;
            $this->updatedAt = $now;
        }
    }

    public function disable(\DateTimeImmutable $now): void
    {
        if ($this->status !== ProjectStatus::DISABLED) {
            $this->status = ProjectStatus::DISABLED;
            $this->updatedAt = $now;
        }
    }

    public function isOperational(): bool
    {
        return $this->status === ProjectStatus::ACTIVE;
    }

    public function allowsPublicDelivery(): bool
    {
        return $this->status === ProjectStatus::ACTIVE || $this->status === ProjectStatus::RESTRICTED;
    }

    public function applyUsageDelta(UsageDelta $delta, \DateTimeImmutable $now): void
    {
        $this->usage = $this->usage->applyDelta($delta);
        $this->updatedAt = $now;
    }

    public function updateQuota(ProjectQuota $newQuota, \DateTimeImmutable $now): void
    {
        $this->quota = $newQuota;
        $this->updatedAt = $now;
    }
}
