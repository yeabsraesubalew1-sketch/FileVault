<?php

declare(strict_types=1);

namespace FileVault\Foundation\Config;

/**
 * Immutable typed database configuration.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
    ) {}
}
