# FileVault M05 - Data and Persistence

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Persistence`  
**Architectural Role:** MySQL Connection Management, Schema Migrations, and Relational Repositories  

---

## 1. Overview & Purpose

The Data and Persistence module manages the relational database layer for FileVault.

It provides raw PDO connection handling, transactional consistency, sequential schema migrations, and typed repositories for Projects, API Keys, and Files.

---

## 2. Directory Structure

```text
src/Persistence/
├── Database.php                              # PDO wrapper, connection factory & transaction manager
├── Exception/
│   ├── ConnectionException.php
│   ├── ConstraintViolationException.php
│   ├── MigrationException.php
│   ├── PersistenceException.php
│   ├── QueryException.php
│   └── TransactionException.php
├── Migration/
│   └── MigrationRunner.php                   # Sequential SQL migration executor
├── Record/
│   ├── ApiKeyRecord.php                      # Read/write DTO for api_keys table
│   ├── FileRecord.php                        # Read/write DTO for files table
│   └── ProjectRecord.php                     # Read/write DTO for projects table
└── Repository/
    ├── ApiKeyRepository.php                  # API Key SQL queries and status updates
    ├── FileRepository.php                    # File metadata CRUD and soft deletion
    └── ProjectRepository.php                 # Project CRUD, quotas, and atomic usage tracking
```

---

## 3. Implemented Components & Contracts

### `Database` (`FileVault\Persistence\Database`)
* `public static function connect(DatabaseConfig $config): self`: Establishes PDO connection with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`, and UTF-8 encoding.
* Transaction methods: `beginTransaction()`, `commit()`, `rollBack()`, and `transaction(callable $callback): mixed` (executes callback inside transaction, auto-rolling back on failure).
* Factory methods: `createProjectRepository()`, `createApiKeyRepository()`, `createFileRepository()`, `createMigrationRunner(string $migrationsPath)`.

### `MigrationRunner` (`FileVault\Persistence\Migration\MigrationRunner`)
* `public function run(): void`: Discovers all `.sql` files in `database/migrations/`, orders them alphanumerically, and executes them in sequence.

### Database Schema
The system relies on 3 core tables:
1. `projects`:
   - Primary key: `id` (BIGINT UNSIGNED AUTO_INCREMENT)
   - Columns: `name`, `status`, `max_file_size`, `max_file_count`, `max_storage_bytes`, `current_file_count`, `current_storage_bytes`, `created_at`, `updated_at`.
   - Unique key on `name`.
2. `api_keys`:
   - Primary key: `id` (BIGINT UNSIGNED AUTO_INCREMENT)
   - Foreign key: `project_id` references `projects(id)`.
   - Columns: `public_id` (BINARY(16) UNIQUE), `name`, `secret_hash` (BINARY(32)), `status`, `created_at`, `revoked_at`.
   - Check constraint enforcing revocation timestamp rules.
3. `files`:
   - Primary key: `id` (BIGINT UNSIGNED AUTO_INCREMENT)
   - Foreign key: `project_id` references `projects(id)`.
   - Columns: `original_name`, `storage_key` (VARCHAR(512) UNIQUE), `mime_type`, `size_bytes`, `visibility` (`public`/`private`), `status` (`active`/`trashed`), `created_at`, `updated_at`, `trashed_at`.

### Repositories
* `ProjectRepository`:
  - `findById(int $id): ?Project`
  - `create(string $name, ProjectQuota $quota, DateTimeImmutable $now): Project`
  - `updateUsage(int $id, UsageDelta $delta, DateTimeImmutable $now): void`
  - `updateQuota(int $id, ProjectQuota $quota, DateTimeImmutable $now): void`
  - `updateStatus(int $id, ProjectStatus $status, DateTimeImmutable $now): void`
* `ApiKeyRepository`:
  - `findByPublicId(string $publicIdBytes): ?ApiKeyRecord`
  - `findByProject(int $projectId): list<ApiKeyRecord>`
  - `create(int $projectId, string $name, string $publicIdBytes, string $secretHashBytes, DateTimeImmutable $now): ApiKeyRecord`
  - `revoke(int $keyId, DateTimeImmutable $now): void`
* `FileRepository`:
  - `create(int $projectId, string $originalName, string $storageKey, string $mimeType, int $sizeBytes, string $visibility, string $status, DateTimeImmutable $now): FileRecord`
  - `findById(int $id): ?FileRecord`
  - `findByStorageKey(string $storageKey): ?FileRecord`
  - `findActiveByProject(int $projectId, int $limit, int $offset): list<FileRecord>`
  - `findTrashedByProject(int $projectId, int $limit, int $offset): list<FileRecord>`
  - `updateStatus(int $id, string $status, ?DateTimeImmutable $trashedAt, DateTimeImmutable $now): void`
  - `updateStorageKey(int $id, string $storageKey, int $sizeBytes, string $mimeType, DateTimeImmutable $now): void`
  - `deletePermanently(int $id): void`

---

## 4. Key Invariants & Policies

1. **Transactional Integrity**: Storage metadata modifications and quota adjustments occur within database transactions to ensure zero desynchronization between disk state and database counters.
2. **Parameterized SQL**: 100% of SQL queries use prepared statements with bound parameters; no raw variables are ever concatenated into queries.
3. **Microsecond Timestamps**: All timestamp columns use `DATETIME(6)` in UTC for exact sub-second ordering.
