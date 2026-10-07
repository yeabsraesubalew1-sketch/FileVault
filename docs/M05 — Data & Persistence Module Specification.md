# M05 — Data & Persistence Module Specification

**Project:** FileVault  
**Module:** M05 — Data & Persistence  
**Status:** **APPROVED — READY FOR IMPLEMENTATION**  
**Specification Version:** 1.0  
**Primary Dependency:** M01 — Core Foundation

---

## 1. Purpose

M05 provides FileVault's persistence infrastructure.

It owns the database connection, schema, migrations, repositories, persistence records, transactions, and database-specific exception translation required by higher-level modules.

M05 provides persistence capabilities without owning business policy.

---

# 2. Responsibilities

M05 owns:

- PDO database connection creation/configuration
- MySQL/MariaDB compatibility
- database schema
- migrations
- migration tracking
- persistence records
- repository implementations
- repository SQL
- database transactions
- database exception translation
- persistence-level validation
- database integration testing

M05 does **not** own:

- HTTP
- API responses
- authentication policy
- API-key generation policy
- project lifecycle policy
- file lifecycle policy
- quota decisions
- filesystem operations
- storage-key generation
- public/private file delivery
- admin UI
- rate limiting
- application-level authorization

Dependency direction:

```text
M05 → M01
```

M05 must not depend on M02–M12.

---

# 3. Database Technology

M05 uses:

- MySQL/MariaDB-compatible relational database
- PDO
- native prepared statements
- explicit SQL

No ORM.

No query builder.

No external database abstraction library.

No generic SQL escape hatch exposed to consuming modules.

M05 must not expose raw PDO connections, PDO statements, or generic `query($sql)` / `execute($sql)` operations to higher modules.

---

# 4. Database Configuration

Database configuration is supplied through M01's configuration system.

M05 consumes the required configuration rather than reading environment variables directly.

Required database configuration conceptually includes:

```text
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

Exact configuration naming follows the M01 configuration contract.

PDO configuration must:

- use exception mode
- use native prepared statements
- disable emulated prepares
- use appropriate UTF-8 connection settings
- avoid exposing credentials in exceptions/logs

Connection creation failures are translated into M05 persistence exceptions.

---

# 5. SQL Conventions

## IDs

All entity IDs use:

```sql
BIGINT UNSIGNED AUTO_INCREMENT
```

Application-facing PHP representation is `int`.

FileVault requires a 64-bit PHP runtime.

No UUID/ULID/custom identifier abstraction is used.

---

## Timestamps

All timestamps use:

```sql
DATETIME(6)
```

Application code generates UTC timestamps.

M05 does not rely on:

```sql
DEFAULT CURRENT_TIMESTAMP
ON UPDATE CURRENT_TIMESTAMP
```

Application timestamps are explicitly supplied to persistence operations.

API-facing conversion to ISO-8601 UTC is owned outside M05.

---

## Character Set / Collation

Tables use `utf8mb4`.

String comparisons must use a deployment-compatible case-insensitive collation where appropriate.

The schema must remain compatible with the supported MySQL/MariaDB deployment environment.

M04 owns slug normalization rules.

M05 enforces the resulting database uniqueness requirement.

---

# 6. Schema

## 6.1 `projects`

```sql
CREATE TABLE projects (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    slug            VARCHAR(120) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    storage_quota   BIGINT UNSIGNED NOT NULL,
    max_file_size   BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    updated_at      DATETIME(6) NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_slug (slug)
);
```

M05 does not enforce a fixed list of project statuses.

M04 owns project-status semantics.

---

## 6.2 `api_keys`

```sql
CREATE TABLE api_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      BIGINT UNSIGNED NOT NULL,
    key_hash        BINARY(32) NOT NULL,
    key_prefix      VARCHAR(16) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    last_used_at    DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_key_hash (key_hash),
    KEY idx_api_keys_project_status (project_id, status),

    CONSTRAINT fk_api_keys_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE RESTRICT
);
```

### Important dependency

`BINARY(32)` is contingent on the final M03 API-key hashing contract being a 32-byte digest.

M05 must not independently define the authentication algorithm.

If M03 changes the required digest representation before M05 implementation begins, the schema must be updated accordingly.

The plaintext API key is never stored.

`key_prefix` is non-secret display/identification metadata.

M03 owns API-key lifecycle semantics.

---

## 6.3 `files`

```sql
CREATE TABLE files (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      BIGINT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    storage_key     VARCHAR(512) NOT NULL,
    mime_type       VARCHAR(127) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    visibility      VARCHAR(16) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    updated_at      DATETIME(6) NOT NULL,
    trashed_at      DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_files_storage_key (storage_key),
    KEY idx_files_project_status_created
        (project_id, status, created_at),

    CONSTRAINT fk_files_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE RESTRICT
);
```

M05 does not define:

- storage-key generation
- file status semantics
- visibility policy

Those belong to M06/M07.

`storage_key` is the canonical storage abstraction identifier.

There is no separate `stored_name` column.

---

# 7. Database Constraints

M05 enforces structural integrity through:

- primary keys
- foreign keys
- unique constraints
- NOT NULL constraints
- unsigned numeric types

M05 does **not** use database `CHECK` constraints for application lifecycle values such as:

```text
active
disabled
revoked
public
private
trashed
```

Those state contracts belong to their owning modules.

Unsigned numeric types already enforce non-negative numeric values where applicable.

---

# 8. Foreign-Key Policy

Foreign keys use:

```sql
ON DELETE RESTRICT
```

for project relationships.

This applies to:

```text
api_keys.project_id → projects.id
files.project_id → projects.id
```

No database cascade deletion is used.

Disabling a project is a status operation and does not delete dependent records.

Permanent project deletion is not a normal v1 operation.

If introduced later, explicit application-level cleanup must occur before project deletion.

---

# 9. Persistence Records

M05 exposes typed immutable persistence records rather than raw PDO arrays.

Records are persistence representations, not domain objects.

Conceptual records:

```text
ProjectRecord
ApiKeyRecord
FileRecord
```

They are:

- `final`
- `readonly`
- fully typed
- immutable
- free of business behavior
- free of HTTP concerns
- free of PDO objects

Example:

```php
final readonly class ProjectRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $status,
        public int $storageQuota,
        public int $maxFileSize,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
```

Equivalent typed records are used for API keys and files.

Database `DATETIME(6)` values are represented internally as `DateTimeImmutable` in UTC.

M05 does not expose raw database rows to consuming modules.

M05 records do not contain methods such as:

```text
disable()
restore()
canUpload()
hasQuota()
```

Business behavior belongs to higher modules.

---

# 10. Repository Contracts

Repositories expose semantically meaningful persistence operations.

They do not expose generic SQL execution.

## 10.1 ProjectRepository

Required operations:

```text
create(ProjectData): ProjectRecord

findById(int $id): ?ProjectRecord

findBySlug(string $slug): ?ProjectRecord

updateProjectSettings(
    int $id,
    string $name,
    string $slug,
    int $storageQuota,
    int $maxFileSize
): ProjectRecord

updateStatus(
    int $id,
    string $status
): ProjectRecord
```

No normal `delete()` operation is required in v1.

---

## 10.2 ApiKeyRepository

Required operations:

```text
create(...): ApiKeyRecord

findByHash(...): ?ApiKeyRecord

findById(int $id): ?ApiKeyRecord

listByProject(int $projectId): list<ApiKeyRecord>

revoke(int $id): ApiKeyRecord

updateLastUsed(int $id, DateTimeImmutable $usedAt): void
```

Repository creation parameters follow the approved M03 key contract.

No plaintext secret is persisted.

No delete operation is provided.

Revocation preserves the database record.

---

## 10.3 FileRepository

Required operations:

```text
create(...): FileRecord

findById(int $id): ?FileRecord

findByProjectAndId(
    int $projectId,
    int $fileId
): ?FileRecord

markTrashed(
    int $projectId,
    int $fileId,
    DateTimeImmutable $trashedAt
): FileRecord

restore(
    int $projectId,
    int $fileId,
    DateTimeImmutable $updatedAt
): FileRecord

purge(
    int $projectId,
    int $fileId
): void
```

`listByProject()` is not mandatory in M05 v1.

It is added only when an approved consuming-module requirement exists.

Project-scoped methods must include the project ID in their persistence query where applicable.

This reinforces project isolation at the repository boundary.

---

# 11. Repository State Guards

Lifecycle operations use state-aware SQL conditions.

Example:

```sql
UPDATE files
SET
    status = ?,
    trashed_at = ?,
    updated_at = ?
WHERE id = ?
  AND project_id = ?
  AND status = 'active'
```

Repository methods must detect when an expected state transition affected no row and return an appropriate persistence-level result/exception according to the defined repository contract.

Repositories do not silently mutate records into invalid lifecycle states.

The exact allowed state transitions remain owned by M07.

---

# 12. Repository Return Semantics

Lookup:

```text
record found → Record
record absent → null
```

Creation/update:

```text
successful operation → Record
```

Deletion/purge:

```text
successful operation → void
```

Repository methods must not return raw affected-row counts to consuming modules unless a specific contract requires them.

---

# 13. Exception Hierarchy

M05 uses a small persistence exception hierarchy:

```text
PersistenceException
├── ConnectionException
├── QueryException
├── ConstraintViolationException
├── TransactionException
└── MigrationException
```

`PersistenceException` is the common M05-level base exception.

### ConnectionException

Used when a database connection cannot be established/configured.

### QueryException

Used for unexpected SQL/query failures.

### ConstraintViolationException

Used when M05 can reliably identify a database constraint violation.

### TransactionException

Used when transaction lifecycle operations fail.

### MigrationException

Used for migration discovery, validation, execution, or tracking failures.

---

## Exception information

Internal exceptions may retain:

- previous exception
- SQLSTATE where safely available
- internal diagnostic information

Sensitive information must not be exposed through public API responses.

M05 must not expose:

- passwords
- connection credentials
- raw SQL containing secrets
- database connection strings
- sensitive database internals

Higher modules decide how persistence exceptions map to application/API errors.

---

# 14. Transactions

M05 provides a minimal transaction wrapper.

Conceptual contract:

```php
$result = $database->transaction(
    function () {
        // repository operations

        return $value;
    }
);
```

Semantics:

```text
BEGIN
    callback
COMMIT

if callback throws:
    ROLLBACK
    rethrow original exception
```

The callback's return value is returned to the caller.

Exceptions are propagated rather than swallowed.

Transaction state is not exposed as a public transaction-management framework.

---

## Transaction limitations

M05 does not provide:

- nested transactions
- savepoints
- distributed transactions
- transaction manager abstractions
- filesystem/database atomicity

MySQL transactions apply only to database operations.

Filesystem operations cannot participate atomically.

M07 owns cross-system sequencing and compensation/failure handling.

Explicit transactions should be used for genuinely multi-operation database work.

Single SQL statements do not require an explicit transaction merely for the sake of using one.

---

# 15. Migrations

Migration files live in:

```text
database/migrations/
```

Naming convention:

```text
001_create_projects.sql
002_create_api_keys.sql
003_create_files.sql
```

The numeric prefix determines execution order.

Migration version is represented as the filename's version component.

---

## Migration tracking table

```sql
CREATE TABLE schema_migrations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version     VARCHAR(100) NOT NULL,
    applied_at  DATETIME(6) NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_version (version)
);
```

---

## Migration runner behavior

The migration runner must:

1. discover migration files
2. validate filenames
3. sort them by version
4. establish a database connection
5. ensure migration tracking exists
6. inspect applied versions
7. execute missing migrations in order
8. record each successfully completed migration
9. stop immediately on failure
10. never record a failed migration as applied

Migrations are executed explicitly.

Application bootstrap must **never automatically mutate the production schema**.

---

## Migration validation

The runner must reject:

- invalid migration filenames
- duplicate migration versions
- duplicate versions in the tracking table
- malformed migration metadata where applicable

No migration checksum system is required in v1.

If an already-applied migration is manually modified, that is an operational/deployment integrity problem rather than something M05 needs a miniature migration framework to solve.

---

## Migration transactions

The runner may use transactions where appropriate.

However, M05 must **not claim universal transactional rollback of DDL**.

MySQL/MariaDB DDL behavior varies and some schema operations implicitly commit.

Migration files should therefore remain:

- small
- deterministic
- ordered
- independently understandable

The runner stops on failure.

---

# 16. Migration CLI

A project CLI entrypoint is required.

Initial conceptual entrypoint:

```text
php bin/migrate.php
```

The exact executable implementation may use the project's approved bootstrap/configuration facilities.

It must:

- load application configuration
- establish the database connection
- run pending migrations
- report success/failure
- return a non-zero process exit code on failure

No web request should be required to execute migrations.

---

# 17. Testing

M05 requires a real MySQL/MariaDB integration-test database.

SQLite is **not** the primary M05 integration-test database.

Reason:

M05 correctness depends on actual target-database behavior including:

- foreign keys
- unique constraints
- unsigned types
- indexes
- timestamp behavior
- SQL syntax
- transaction behavior
- migration behavior

---

## Unit tests

Unit tests cover DB-independent logic such as:

- migration filename/version parsing
- migration ordering
- record mapping
- persistence configuration validation
- exception classification/translation where practical

---

## Integration tests

Integration tests use a dedicated test database and cover:

- migration installation
- migration ordering
- migration tracking
- schema creation
- repository creation
- repository reads
- repository updates
- project isolation
- foreign-key restrictions
- unique constraints
- state guards
- timestamp persistence
- transaction commit
- transaction rollback
- constraint exception translation
- missing-record behavior

Production databases must never be used for tests.

---

# 18. Integration-Test Reset Strategy

The test environment uses a dedicated database/schema.

Migration-specific tests may:

- start from an empty schema
- run the migration suite
- verify the resulting schema/tracking state

Repository integration tests may:

- apply migrations once for the suite
- use controlled transactions for isolated tests where practical
- explicitly clean/reset test data when transaction rollback cannot cover the scenario

Tests must not depend on execution order unless explicitly designed as migration tests.

No SQLite compatibility layer is required.

---

# 19. Persistence and Filesystem Boundary

M05 stores file metadata only.

M05 does not:

- create files
- move files
- delete physical files
- calculate filesystem paths
- inspect storage directories

For files:

```text
M05
    ↓
storage_key metadata
```

M06 owns:

```text
storage_key
    ↓
physical storage
```

No higher module should construct a physical filesystem path from M05 data.

---

# 20. Cross-System Failure Model

Database and filesystem operations cannot be treated as one atomic transaction.

For example:

```text
filesystem success
→ database failure
```

must not be hidden by M05.

Likewise:

```text
database success
→ filesystem failure
```

must not be disguised as successful storage.

M07 owns lifecycle sequencing, recovery/compensation, and consistency handling between M05 and M06.

M05 only guarantees the correctness of its own database operation.

---

# 21. Security Requirements

M05 must:

- use prepared statements
- never concatenate untrusted values into SQL
- never store plaintext API-key secrets
- avoid logging credentials
- avoid exposing database credentials
- avoid exposing SQL/database details through public errors
- enforce project foreign-key relationships
- use project-scoped repository operations where required
- use least-privilege database credentials in deployment where operationally possible

M05 does not implement authentication or authorization.

---

# 22. Indexing Strategy

Required indexes:

### Projects

```text
PRIMARY KEY(id)
UNIQUE(slug)
```

### API keys

```text
PRIMARY KEY(id)
UNIQUE(key_hash)
INDEX(project_id, status)
```

### Files

```text
PRIMARY KEY(id)
UNIQUE(storage_key)
INDEX(project_id, status, created_at)
```

No additional indexes should be introduced merely for symmetry.

Additional indexes require an actual repository query/use case.

---

# 23. Data Ownership

| Data | Owner |
|---|---|
| Project persistence | M05 |
| Project lifecycle policy | M04 |
| API-key persistence | M05 |
| API-key authentication policy | M03 |
| API-key format/hash policy | M03 |
| File metadata persistence | M05 |
| File lifecycle policy | M07 |
| Storage-key semantics | M06 |
| Physical files | M06 |
| Quota policy | M04 |
| HTTP/API representation | M02 |
| Public delivery | M08 |
| Admin representation | M09 |

M05 is the persistence owner, not the business-policy owner.

---

# 24. Explicit Non-Goals

M05 v1 does not include:

- ORM
- query builder
- generic repository base class
- generic CRUD repository
- generic SQL execution API
- domain entities
- domain services
- event sourcing
- audit-log subsystem
- database-backed job queue
- soft-delete framework
- migration checksum framework
- migration rollback framework
- nested transactions
- savepoints
- distributed transactions
- database/filesystem atomicity
- read replicas
- connection pooling infrastructure
- sharding
- caching layer

These are intentionally excluded unless a concrete FileVault requirement later justifies them.

---

# 25. Acceptance Criteria

M05 is acceptable when all of the following are true.

### Database

- [ ] PDO connection works against the supported MySQL/MariaDB environment.
- [ ] PDO uses exception mode.
- [ ] Native prepared statements are used.
- [ ] UTF-8 database communication is configured.
- [ ] Connection failures are translated appropriately.
- [ ] No database credentials are exposed in errors/logs.

### Schema

- [ ] `projects` schema matches the approved contract.
- [ ] `api_keys` schema matches the approved contract.
- [ ] `files` schema matches the approved contract.
- [ ] `schema_migrations` exists.
- [ ] Primary keys are correct.
- [ ] Unique constraints are correct.
- [ ] Foreign keys are correct.
- [ ] `ON DELETE RESTRICT` is enforced.
- [ ] Timestamp types are `DATETIME(6)`.
- [ ] IDs use `BIGINT UNSIGNED AUTO_INCREMENT`.
- [ ] No unintended business-state CHECK constraints are introduced.

### Repositories

- [ ] Repositories expose explicit semantic operations.
- [ ] No generic SQL escape hatch is exposed.
- [ ] Repositories return typed immutable records.
- [ ] Lookup absence returns `null`.
- [ ] Project-scoped file operations enforce project scope.
- [ ] State-guarded operations behave correctly.
- [ ] No unauthorized lifecycle behavior is embedded in M05.

### Transactions

- [ ] Transaction callback receives control of the DB operation.
- [ ] Successful callbacks commit.
- [ ] Exceptions cause rollback.
- [ ] Original exceptions propagate.
- [ ] Callback return values are preserved.
- [ ] No nested/savepoint framework exists.

### Migrations

- [ ] Migration naming is validated.
- [ ] Migrations execute in deterministic order.
- [ ] Applied migrations are tracked.
- [ ] Successful migrations are recorded.
- [ ] Failed migrations are not recorded as successful.
- [ ] Duplicate versions are rejected.
- [ ] Application bootstrap does not automatically migrate production.
- [ ] CLI migration execution returns an appropriate process status.

### Testing

- [ ] Dedicated real MySQL/MariaDB test database is used.
- [ ] SQLite is not used as the primary integration database.
- [ ] Migration tests pass.
- [ ] Repository integration tests pass.
- [ ] FK restrictions are tested.
- [ ] Unique constraints are tested.
- [ ] Project isolation is tested.
- [ ] Transaction commit/rollback is tested.
- [ ] Persistence exception translation is tested where applicable.

### Boundary

- [ ] M05 depends only on M01.
- [ ] M05 contains no HTTP logic.
- [ ] M05 contains no filesystem logic.
- [ ] M05 contains no authentication policy.
- [ ] M05 contains no project/file lifecycle policy.
- [ ] M05 does not construct storage paths.
- [ ] M05 does not expose raw PDO infrastructure to higher modules.

---

# 26. Implementation Constraint

The implementer must implement this specification without silently changing:

- schema ownership
- repository ownership
- dependency direction
- public repository contracts
- transaction semantics
- migration semantics
- security boundaries
- lifecycle ownership

Local implementation details may be chosen where the specification intentionally leaves them open.

Any material conflict must be reported before implementation continues.

---

# 27. Final Status

**M05 — Data & Persistence is APPROVED and READY FOR IMPLEMENTATION.**

The only explicitly external contract dependency is the final M03 API-key hashing representation.

M05 does not independently decide that authentication contract.

Subject to that interface being compatible with the provisional `BINARY(32)` representation—or the schema being adjusted before implementation if M03 chooses otherwise—there are no remaining M05 architectural blockers.

Next workflow:

```text
M05 Specification
        ↓
Antigravity Implementation
        ↓
Implementation Summary
        ↓
READY FOR REVIEW
        ↓
M05 Review
        ↓
Acceptance
        ↓
M06 — Storage Backend
```