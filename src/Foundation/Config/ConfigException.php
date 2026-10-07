<?php

declare(strict_types=1);

namespace FileVault\Foundation\Config;

use RuntimeException;

class ConfigException extends RuntimeException
{
    public static function missingRequired(string $key): self
    {
        return new self("Missing required configuration value for: {$key}");
    }

    public static function invalidType(string $key, string $expectedType): self
    {
        return new self("Invalid configuration type for {$key}. Expected {$expectedType}.");
    }

    public static function invalidValue(string $key, string $reason): self
    {
        return new self("Invalid configuration value for {$key}: {$reason}");
    }
}
