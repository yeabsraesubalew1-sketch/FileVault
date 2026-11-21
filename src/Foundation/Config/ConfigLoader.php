<?php

declare(strict_types=1);

namespace FileVault\Foundation\Config;

use Dotenv\Dotenv;

final class ConfigLoader
{
    /**
     * Loads configuration from environment and optional .env file.
     *
     * @param string $basePath The directory containing the .env file.
     * @return Config
     * @throws ConfigException
     */
    public static function load(string $basePath): Config
    {
        // Load .env if it exists (for local development)
        if (file_exists($basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }

        return new Config(
            appEnv: self::parseAppEnv(),
            appDebug: self::parseBoolean('APP_DEBUG', false),
            appUrl: self::parseUrl('APP_URL'),
            database: new DatabaseConfig(
                host: self::getString('DB_HOST'),
                port: self::parseInt('DB_PORT', 3306),
                database: self::getString('DB_DATABASE'),
                username: self::getString('DB_USERNAME'),
                password: self::getString('DB_PASSWORD', '')
            ),
            storage: new StorageConfig(
                storageRoot: self::getString('STORAGE_ROOT')
            ),
            adminPassword: self::getString('ADMIN_PASSWORD', 'admin123'),
            adminUsername: self::getString('ADMIN_USERNAME', 'admin')
        );
    }

    private static function getString(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            if ($default !== null) {
                return $default;
            }
            throw ConfigException::missingRequired($key);
        }

        return (string) $value;
    }

    private static function parseAppEnv(): AppEnv
    {
        $value = self::getString('APP_ENV', 'production');
        $env = AppEnv::tryFrom(strtolower($value));

        if ($env === null) {
            throw ConfigException::invalidValue('APP_ENV', "Must be 'production', 'development', or 'testing'");
        }

        return $env;
    }

    private static function parseBoolean(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        $value = strtolower((string) $value);

        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($value, ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        throw ConfigException::invalidType($key, 'boolean');
    }

    private static function parseUrl(string $key): string
    {
        $value = self::getString($key);
        
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            throw ConfigException::invalidType($key, 'valid URL');
        }

        return rtrim($value, '/');
    }

    private static function parseInt(string $key, ?int $default = null): int
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            if ($default !== null) {
                return $default;
            }
            throw ConfigException::missingRequired($key);
        }

        if (!is_numeric($value)) {
            throw ConfigException::invalidType($key, 'integer');
        }

        return (int) $value;
    }
}
