<?php

declare(strict_types=1);

namespace FileVault\Project;

final readonly class QuotaCheckResult
{
    /**
     * @param string[] $violations
     */
    public function __construct(
        public bool $allowed,
        public array $violations = []
    ) {}
}
