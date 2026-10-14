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

    private ?\FileVault\Persistence\Database $database = null;
    private ?\FileVault\Storage\StorageBackend $storageBackend = null;
    private ?\FileVault\Project\ProjectRepository $projectRepository = null;
    private ?\FileVault\Project\ProjectService $projectService = null;
    private ?\FileVault\ApiKey\Persistence\ApiKeyRepository $apiKeyRepository = null;
    private ?\FileVault\Persistence\Repository\FileRepository $fileRepository = null;
    private ?\FileVault\Lifecycle\FileLifecycle $fileLifecycle = null;
    private ?\FileVault\ApiKey\Application\AuthenticateApiKey $authenticateApiKey = null;
    private ?\FileVault\ApiKey\Application\CreateApiKey $createApiKey = null;

    /**
     * Retrieves the typed immutable configuration.
     * 
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getDatabase(): \FileVault\Persistence\Database
    {
        return $this->database ??= new \FileVault\Persistence\Database($this->config);
    }

    public function getStorageBackend(): \FileVault\Storage\StorageBackend
    {
        return $this->storageBackend ??= new \FileVault\Storage\Local\LocalFilesystemStorage($this->config->storage);
    }

    public function getProjectRepository(): \FileVault\Project\ProjectRepository
    {
        return $this->projectRepository ??= $this->getDatabase()->createProjectRepository();
    }

    public function getProjectService(): \FileVault\Project\ProjectService
    {
        return $this->projectService ??= new \FileVault\Project\ProjectService($this->getProjectRepository());
    }

    public function getApiKeyRepository(): \FileVault\ApiKey\Persistence\ApiKeyRepository
    {
        return $this->apiKeyRepository ??= $this->getDatabase()->createApiKeyRepository();
    }

    public function getFileRepository(): \FileVault\Persistence\Repository\FileRepository
    {
        return $this->fileRepository ??= $this->getDatabase()->createFileRepository();
    }

    public function getFileLifecycle(): \FileVault\Lifecycle\FileLifecycle
    {
        return $this->fileLifecycle ??= new \FileVault\Lifecycle\FileLifecycle(
            $this->getDatabase(),
            $this->getStorageBackend()
        );
    }

    public function getAuthenticateApiKey(): \FileVault\ApiKey\Application\AuthenticateApiKey
    {
        return $this->authenticateApiKey ??= new \FileVault\ApiKey\Application\AuthenticateApiKey(
            new \FileVault\ApiKey\Credential\ApiKeyCredentialParser(),
            new \FileVault\ApiKey\Credential\ApiKeySecretVerifier(
                new \FileVault\ApiKey\Credential\ApiKeySecretHasher()
            ),
            $this->getApiKeyRepository()
        );
    }

    public function getCreateApiKey(): \FileVault\ApiKey\Application\CreateApiKey
    {
        return $this->createApiKey ??= new \FileVault\ApiKey\Application\CreateApiKey(
            new \FileVault\ApiKey\Credential\ApiKeyGenerator(),
            new \FileVault\ApiKey\Credential\ApiKeySecretHasher(),
            $this->getApiKeyRepository()
        );
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
