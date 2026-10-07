<?php

declare(strict_types=1);

namespace FileVault\Storage;

final readonly class StoredObject
{
    public function __construct(
        public string $storageKey,
        public int $sizeBytes,
    ) {}
}
