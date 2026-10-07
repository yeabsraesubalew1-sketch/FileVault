<?php

declare(strict_types=1);

namespace FileVault\Foundation\Bootstrap;

use FileVault\Foundation\Config\Config;
use FileVault\Foundation\Config\ConfigLoader;
use Throwable;

/**
 * The composition root and foundation container.
 */
final class App
{
    private Config $config;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Bootstraps the application, validating runtime and configuration.
     *
     * @param string $basePath The root directory of the project.
     * @return self
     */
    public static function bootstrap(string $basePath): self
    {
        self::checkRuntimeRequirements();
        
        $config = ConfigLoader::load($basePath);
        
        // Initialization of shared infrastructure primitives goes here.
        
        return new self($config);
    }

    /**
     * Retrieves the typed immutable configuration.
     * 
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    private static function checkRuntimeRequirements(): void
    {
        if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) {
            throw BootstrapException::phpVersionNotSupported('~8.4.0', PHP_VERSION);
        }

        $requiredExtensions = ['mbstring', 'json'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                throw BootstrapException::extensionMissing($ext);
            }
        }
    }


}
