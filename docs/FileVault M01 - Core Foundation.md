# FileVault M01 - Core Foundation

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Foundation`  
**Architectural Role:** System Bootstrap, Configuration Management, and Dependency Container  

---

## 1. Overview & Purpose

The Core Foundation module establishes the foundational infrastructure required for FileVault to boot safely, validate runtime requirements, load typed environment configuration, and construct shared services.

M01 contains zero domain business logic. It sits at the lowest layer of the dependency graph, providing the application composition root.

---

## 2. Directory Structure

```text
src/Foundation/
├── Bootstrap/
│   ├── App.php                   # Composition root & service locator
│   └── BootstrapException.php    # Failure during initialization
├── Config/
│   ├── AppEnv.php                # Environment Enum (production, development, testing)
│   ├── Config.php                # Immutable typed application configuration
│   ├── ConfigException.php       # Configuration syntax or validation error
│   ├── ConfigLoader.php          # Loads .env and constructs typed Config
│   ├── DatabaseConfig.php        # MySQL connection credentials DTO
│   └── StorageConfig.php         # Disk root and storage settings DTO
└── Support/
    ├── RequestId.php             # Request trace identifier value object
    └── RequestIdGenerator.php    # Generates cryptographically unique req_* IDs
```

---

## 3. Implemented Components & Contracts

### `App` (`FileVault\Foundation\Bootstrap\App`)
The singleton-style composition root for dependency construction:
* `public static function bootstrap(string $basePath): self`: Validates PHP runtime requirements (PHP >= 8.2, `pdo_mysql`, `fileinfo`, `mbstring`, `json`), loads environment configuration via `ConfigLoader`, and returns the initialized `App` container.
* `public function getConfig(): Config`: Returns the immutable application configuration.
* Lazy accessors for core services: `getDatabase()`, `getStorageBackend()`, `getProjectRepository()`, `getProjectService()`, `getApiKeyRepository()`, `getFileRepository()`, `getFileLifecycle()`, `getAuthenticateApiKey()`, `getCreateApiKey()`.

### `ConfigLoader` (`FileVault\Foundation\Config\ConfigLoader`)
Loads and normalizes environment variables:
* Loads `.env` file from the project base path via `vlucas/phpdotenv`.
* Parses database credentials (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
* Parses storage root (`STORAGE_ROOT`, resolving relative paths against the project root).
* Parses admin credentials (`ADMIN_USERNAME`, `ADMIN_PASSWORD`).
* Enforces validation: Rejects missing mandatory variables and invalid port numbers.

### `Config` (`FileVault\Foundation\Config\Config`)
Read-only value object holding validated configuration:
* `public readonly AppEnv $appEnv`
* `public readonly bool $appDebug`
* `public readonly string $appUrl`
* `public readonly DatabaseConfig $database`
* `public readonly StorageConfig $storage`
* `public readonly string $adminUsername`
* `public readonly string $adminPassword`

### `RequestIdGenerator` (`FileVault\Foundation\Support\RequestIdGenerator`)
Generates unique 16-byte random identifiers prefixed with `req_` (e.g. `req_4f1b8a92...`) for correlating logs, error envelopes, and request lifecycles.

---

## 4. Key Behaviors & Invariants

1. **Fail-Fast Bootstrap**: If mandatory extensions (`pdo_mysql`, `fileinfo`, `mbstring`) are missing or `.env` cannot be parsed, `App::bootstrap()` halts immediately with a clear `BootstrapException`.
2. **Immutable Runtime Settings**: Configuration values are strictly read-only after bootstrap and cannot be modified at runtime.
3. **No Direct Environment Access**: Domain modules must never call `getenv()` or read `$_ENV` directly; all configuration is injected through `Config`.

---

## 5. Dependencies

* **PHP Extensions**: `pdo_mysql`, `fileinfo`, `mbstring`, `json`.
* **External Packages**: `vlucas/phpdotenv` (v5.6+).
* **Dependent Modules**: All modules (M02 through M12) depend on M01 for configuration and bootstrap services.
