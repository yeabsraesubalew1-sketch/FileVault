<?php

declare(strict_types=1);

namespace FileVault\Foundation\Config;

/**
 * Storage configuration.
 */
final readonly class StorageConfig
{
    public function __construct(
        public string $storageRoot,
    ) {}
}
