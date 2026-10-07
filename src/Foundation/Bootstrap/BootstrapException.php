<?php

declare(strict_types=1);

namespace FileVault\Foundation\Bootstrap;

use RuntimeException;

class BootstrapException extends RuntimeException
{
    public static function phpVersionNotSupported(string $required, string $current): self
    {
        return new self("Unsupported PHP version. Required: {$required}, Current: {$current}");
    }

    public static function extensionMissing(string $extension): self
    {
        return new self("Required PHP extension missing: {$extension}");
    }
}
