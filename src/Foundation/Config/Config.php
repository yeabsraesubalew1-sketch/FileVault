<?php

declare(strict_types=1);

namespace FileVault\Foundation\Config;

/**
 * Immutable typed configuration representation.
 */
final readonly class Config
{
    public function __construct(
        public AppEnv $appEnv,
        public bool $appDebug,
        public string $appUrl,
        public DatabaseConfig $database,
        public StorageConfig $storage,
    ) {}
}
