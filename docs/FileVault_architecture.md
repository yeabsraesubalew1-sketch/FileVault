# FileVault Architecture

**Status:** ACCEPTED / IMPLEMENTED — System Architecture & Delivery Baseline  
**Document role:** System architecture and module boundary source of truth  
**Project:** FileVault  
**Architecture owner:** Architect  
**Last updated:** 2026-10-14

---

## 1. Purpose

FileVault is a lightweight, self-hosted, multi-project file-storage API intended for personal web applications and projects.

It provides authenticated server-to-server file operations while storing file bytes on the hosting filesystem and file metadata in MySQL. The architecture is intentionally storage-backend-independent so that local filesystem storage can later be replaced by object storage without redesigning the consuming applications.

FileVault is **not** intended to compete with S3-class infrastructure. Its purpose is to provide a controlled, reusable storage service under the project's existing Bluehost/shared-hosting constraints.

---

## 2. Goals

### Primary goals

- Provide a reusable file-storage API for multiple consuming projects.
- Support project-scoped authentication and authorization.
- Store file bytes reliably on the local filesystem.
- Persist searchable file metadata in MySQL.
- Provide public asset URLs.
- Validate uploads and restrict dangerous file types.
- Support quotas, file-size limits, and rate limiting.
- Provide soft deletion and trash isolation.
- Provide a protected administrator interface for project and credential management.
- Produce stable API contracts through `/api/v1`.
- Keep the API independent from the underlying storage implementation.
- Remain compatible with CDN delivery.
- Operate within normal Bluehost/shared-hosting constraints.

### Non-goals for v1

- S3 compatibility.
- Public user registration.
- Browser-side exposure of project API keys.
- Resumable/chunked uploads.
- Full private-file signed URL infrastructure.
- Automated backup infrastructure.
- Automatic trash purging.
- Large-scale tenant administration.
- Built-in media processing/transcoding.
- Deep CDN vendor integration.

---

## 3. Architectural Principles

1. **API before storage.** Consuming applications interact with FileVault through the API, not directly with its filesystem.
2. **Storage abstraction.** The application must not depend directly on filesystem details outside the storage boundary.
3. **Project isolation.** Every authenticated project operation is scoped to one project.
4. **Server-to-server credentials.** Project API keys are secrets and must remain server-side.
5. **Immutable stored files.** Replacement creates a new stored object rather than overwriting an existing object.
6. **Metadata follows successful storage.** Successful physical storage precedes successful metadata creation.
7. **Public retrieval does not require project credentials.** Public assets can be served directly.
8. **Administrative control is separate from project API access.**
9. **No security by obscurity.** Hidden endpoints, filenames, or URLs are not authentication mechanisms.
10. **Operational simplicity over premature abstraction.** v1 should be small, explicit, and maintainable.
11. **Future migration must be possible.** The public API and metadata model should not encode assumptions that make object-storage migration unnecessarily difficult.

---

## 4. System Overview

```text
                    ┌─────────────────────────────┐
                    │       Consuming Apps        │
                    │ Mela Space / WorkSieve / ...│
                    └──────────────┬──────────────┘
                                   │ HTTPS
                                   │ API key
                                   ▼
                    ┌─────────────────────────────┐
                    │          FileVault           │
                    │                             │
                    │ Router / API Controllers    │
                    │          ↓                  │
                    │ Authentication / AuthZ      │
                    │          ↓                  │
                    │ Application Services        │
                    │       ↙          ↘          │
                    │ Metadata Repo    Storage    │
                    └───────┬───────────┬─────────┘
                            │           │
                            ▼           ▼
                         MySQL      Local Filesystem
                            │           │
                            └─────┬─────┘
                                  │
                            Public asset
                               delivery
                                  │
                                  ▼
                              Browser/CDN

                    ┌─────────────────────────────┐
                    │       Admin Interface        │
                    │ login → session → CSRF      │
                    │ project/key management      │
                    └──────────────┬──────────────┘
                                   │
                                   ▼
                              FileVault
```

---

## 5. Deployment Architecture

### Runtime

- PHP 8.x
- Apache
- MySQL
- HTTPS
- Bluehost/shared hosting

### Recommended filesystem placement

Prefer:

```text
account/
├── public_html/
│   └── FileVault public entrypoint
└── filevault-data/
    ├── storage/
    ├── logs/
    └── temporary/private runtime data
```

If hosting constraints require storage below the web root, direct web access to non-public data must be explicitly denied.

The following must never be publicly readable:

- `.env`
- API credentials
- logs
- temporary upload data
- trash
- internal configuration
- database dumps
- arbitrary runtime files

---

## 6. Module Map

The project is divided into the following architectural modules.

| ID | Module | Responsibility | Primary Dependencies |
|---|---|---|---|
| M01 | Core Foundation | Configuration, bootstrap, environment, shared primitives, request IDs | None |
| M02 | HTTP/API Layer | Routing, controllers, request/response handling, API versioning | M01 |
| M03 | Authentication & Authorization | API-key authentication, project scoping, permissions | M01, M02, M04 |
| M04 | Project Management | Projects, status, quotas, limits, project configuration | M01, M05 |
| M05 | Data & Persistence | MySQL connection, migrations/schema, repositories, transactions | M01 |
| M06 | Storage Backend | Filesystem abstraction, paths, physical file operations | M01, M04 |
| M07 | File Lifecycle | Upload, validation, replacement, deletion, metadata lifecycle | M02, M03, M04, M05, M06 |
| M08 | Public File Delivery | Public retrieval, headers, missing-file handling, public URLs | M05, M06 |
| M09 | Admin Interface | Admin login/session, project management UI, API-key management UI | M02, M04, M05 |
| M10 | Security & Abuse Controls | Rate limiting, quotas, CORS policy, security headers, abuse controls | M03, M04, M05 |
| M11 | Observability & Errors | Error envelope, request IDs, structured logs, audit/security events | M01, M02, M05 |
| M12 | Operations & Deployment | Installation, environment configuration, Apache rules, deployment, maintenance | M01, M05, M06, M11 |

### Ownership model

The architecture establishes **module ownership roles**, not named individuals.

For each module:

- **Module Owner:** accountable for its specification, contracts, dependencies, edge cases, and acceptance.
- **Implementer:** implements the approved module specification.
- **Architect:** owns system-level boundaries and resolves cross-module architectural conflicts.
- **Review/Integration:** verifies module integration and project-level compatibility.

A module owner remains accountable even when implementation is delegated.

---

## 7. Module Boundaries

### M01 — Core Foundation

Owns:

- environment/configuration loading
- application bootstrap
- dependency wiring
- common constants/value objects
- request ID generation
- shared utility boundaries

Must not own business rules for projects or files.

### M02 — HTTP/API Layer

Owns:

- `/api/v1`
- route registration
- controller boundaries
- HTTP status mapping
- request parsing
- JSON response formatting
- API versioning

Must not contain storage or SQL business logic.

### M03 — Authentication & Authorization

Owns:

- API-key verification
- key hashing
- project association
- key status
- endpoint permission checks
- project-scoped authorization context

Must not create or modify files directly.

### M04 — Project Management

Owns:

- projects
- project lifecycle/status
- project configuration
- storage quotas
- maximum file size
- project-level operational settings

Project creation is an administrative operation, not a public API operation.

### M05 — Data & Persistence

Owns:

- MySQL connection management
- schema/migrations
- repositories
- database transactions
- persistence models

This module provides persistence capabilities but does not decide business policy.

### M06 — Storage Backend

Owns:

- storage abstraction
- local filesystem implementation
- generated storage paths
- physical file moves
- existence checks
- trash moves
- storage-level errors

The rest of the application must not construct filesystem paths directly.

### M07 — File Lifecycle

Owns the business lifecycle of a file:

```text
request
 → authenticate
 → validate
 → store
 → persist metadata
 → deliver
 → replace/delete
 → trash
```

Owns:

- upload orchestration
- file validation policy
- generated file identity
- metadata lifecycle
- replacement semantics
- deletion semantics
- orphan/error handling

### M08 — Public File Delivery

Owns:

- public file retrieval
- public URL resolution
- content-type handling
- caching headers
- 404 behavior for missing physical files

Public delivery must never expose internal filesystem paths.

### M09 — Admin Interface

Owns:

- administrator authentication/session
- CSRF protection
- admin pages
- project management UI
- API-key generation/revocation UI
- operational configuration UI

The admin interface is a separate trust surface from project API authentication.

### M10 — Security & Abuse Controls

Owns cross-cutting controls including:

- API rate limits
- upload limits
- quota enforcement
- CORS policy
- security headers
- suspicious request controls
- dangerous file-type policy

This module provides policy/mechanisms; business ownership remains with the module whose resource is being protected.

### M11 — Observability & Errors

Owns:

- standard error envelope
- request IDs
- internal logging
- security events
- storage inconsistency events
- safe production error responses

Example:

```json
{
  "error": {
    "code": "file_too_large",
    "message": "The uploaded file exceeds the maximum allowed size.",
    "request_id": "req_..."
  }
}
```

Detailed exceptions must remain server-side.

### M12 — Operations & Deployment

Owns:

- production deployment
- environment setup
- Apache configuration
- filesystem permissions
- cron integration when introduced
- deployment checklist
- maintenance procedures

---

## 8. Data Architecture

The persistence model is implemented in MySQL/MariaDB across three primary relational entities via migrations in `database/migrations`:

### projects (Migration 001)

Authoritative project configuration, operational status, quota definitions, and usage counters:

```sql
CREATE TABLE projects (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                    VARCHAR(120) NOT NULL,
    status                  VARCHAR(20) NOT NULL, -- 'active', 'restricted', 'disabled'
    max_file_size           BIGINT UNSIGNED NOT NULL,
    max_file_count          BIGINT UNSIGNED NOT NULL,
    max_storage_bytes       BIGINT UNSIGNED NOT NULL,
    current_file_count      BIGINT UNSIGNED NOT NULL,
    current_storage_bytes   BIGINT UNSIGNED NOT NULL,
    created_at              DATETIME(6) NOT NULL,
    updated_at              DATETIME(6) NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_name (name)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### api_keys (Migration 002)

High-entropy project credentials with separate public ID and hashed secret bytes:

```sql
CREATE TABLE api_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       BINARY(16) NOT NULL,
    project_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    secret_hash     BINARY(32) NOT NULL, -- SHA-256 binary hash
    status          VARCHAR(20) NOT NULL, -- 'active', 'revoked'
    created_at      DATETIME(6) NOT NULL,
    revoked_at      DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_public_id (public_id),
    UNIQUE KEY uq_api_keys_project_name (project_id, name),
    KEY idx_api_keys_project_status (project_id, status),

    CONSTRAINT chk_api_keys_status_revoked_at CHECK (
        (status = 'active' AND revoked_at IS NULL) OR 
        (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_at >= created_at)
    ),
    CONSTRAINT fk_api_keys_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### files (Migration 003)

File metadata, visibility status, lifecycle state, and storage key mapping:

```sql
CREATE TABLE files (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      BIGINT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    storage_key     VARCHAR(512) NOT NULL, -- 'YYYY/MM/<64-char-hex-token>'
    mime_type       VARCHAR(127) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    visibility      VARCHAR(16) NOT NULL,  -- 'public', 'private'
    status          VARCHAR(20) NOT NULL,  -- 'active', 'trashed'
    created_at      DATETIME(6) NOT NULL,
    updated_at      DATETIME(6) NOT NULL,
    trashed_at      DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_files_storage_key (storage_key),
    KEY idx_files_project_status_created (project_id, status, created_at),

    CONSTRAINT fk_files_project
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Key rule

File identity is independent of:

- consuming domain
- original filename
- physical directory layout

This allows public URL/domain changes and storage migrations without rewriting logical file identity.

---

## 9. Authentication & Authorization

### Project API

Project clients authenticate with a high-entropy API key.

The key format is:
```text
fvk_<base64url(16-byte-public-id)>_<base64url(32-byte-secret)>
```
Example:
```text
fvk_eySnduHEIVZanfD_vWg_cg_8VvqEbGy01aXs1adidLXuDn_tkx1sQxfDQlIll3PaMA
```

The key is:

- Generated server-side using cryptographically secure random bytes (`random_bytes`).
- Displayed **once** upon creation via `bin/console.php key:create`.
- Stored as a raw 32-byte SHA-256 hash in MySQL (`secret_hash`).
- Sent by clients using either the `X-API-Key` HTTP header or the `Authorization: Bearer <key>` header.
- Verified in constant time using `hash_equals()` to prevent timing attacks.
- Kept strictly server-side in consuming applications (never exposed to browser JS).

Authentication & Authorization flow:

```text
API request
 → extract API key (X-API-Key or Authorization header)
 → parse public ID & secret bytes
 → lookup key record by public ID
 → constant-time hash verification
 → resolve project
 → verify project is operational (ACTIVE)
 → establish authorized context
 → execute controller action
```

---

## 10. API Architecture

Base path: `/api/v1/`

### Implemented Endpoints

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| `GET` | `/health` / `/api/v1/health` | No | System health and version status |
| `GET` | `/api/v1/project` | Yes | Current project info, quota limits, and live usage stats |
| `POST` | `/api/v1/files` | Yes | Upload file (multipart form with `file`, optional `name`, `visibility`) |
| `GET` | `/api/v1/files` | Yes | List project files with pagination (`limit`, `offset`) and `status` filter |
| `GET` | `/api/v1/files/{id}` | Yes | Retrieve single file metadata by project-scoped ID |
| `DELETE` | `/api/v1/files/{id}` | Yes | Soft-delete file (moves physical file to trash directory) |
| `POST` | `/api/v1/files/{id}/replace` | Yes | Replace file bytes (creates new stored object, trashes previous) |
| `POST` | `/api/v1/files/{id}/restore` | Yes | Restore trashed file back to active state |
| `DELETE` | `/api/v1/files/{id}/purge` | Yes | Permanently purge trashed file from disk and database |
| `GET` | `/files/{storageKey}` | No | Public asset retrieval with HTTP caching & ETag support |
| `GET` | `/f/{id}` | No | Public asset retrieval by file ID alias |
| `GET` | `/favicon.ico` / `/favicon.svg` | No | Brand favicon delivery with cache control |
| `GET` | `/admin/login` | No | Administrator login screen |
| `POST` | `/admin/login` | No (CSRF) | Authenticate admin session via `ADMIN_PASSWORD` |
| `GET` / `POST` | `/admin/logout` | Session | Terminate admin session and destroy cookies |
| `GET` | `/admin` | Session | Admin control plane dashboard with projects and metrics |
| `POST` | `/admin/projects` | Session + CSRF | Create new project bucket with quota limits |
| `POST` | `/admin/projects/{id}/status` | Session + CSRF | Update project operational status (active/restricted/disabled) |
| `POST` | `/admin/projects/{id}/quota` | Session + CSRF | Update project quota configuration |
| `POST` | `/admin/projects/{id}/keys` | Session + CSRF | Generate project API key (displays plaintext credential once) |
| `POST` | `/admin/keys/{id}/revoke` | Session + CSRF | Revoke active API key immediately |

---

## 11. Upload Lifecycle

For v1:

```text
HTTP request
 → authenticate
 → authorize
 → validate multipart upload
 → validate size/type
 → generate file identity
 → write/move physical file
 → verify storage success
 → create metadata
 → return 201
```

Metadata is not committed as a successful file record before physical storage succeeds.

If storage succeeds but database persistence fails, the system may temporarily contain an orphaned physical file. The event must be logged and future reconciliation can address it.

Interrupted uploads should fail through normal PHP upload handling. Resumable/chunked upload is deferred.

---

## 12. File Naming and Storage Layout

Original filenames are metadata only.

Stored filenames are generated and collision-resistant.

Conceptual layout:

```text
storage/
└── projects/
    └── <project-id-or-key>/
        ├── active/
        │   └── YYYY/
        │       └── MM/
        │           └── <generated-storage-name>
        └── trash/
            └── YYYY/
                └── MM/
                    └── <generated-storage-name>
```

The exact path/key representation belongs to M06.

Application code should work with a storage key/identifier rather than assuming physical paths.

---

## 13. File Visibility

Schema supports:

```text
public
private
```

### v1

Public files are the supported retrieval mode.

Public retrieval does not require API credentials.

### Deferred

Private files will require an authenticated or signed retrieval mechanism.

---

## 14. Deletion and Replacement

### Deletion

v1 uses soft deletion:

```text
active file
   ↓
move physical object to trash
   ↓
mark metadata deleted
```

Trash is not a backup.

### Replacement

Replacement does not overwrite an existing stored object.

Instead:

```text
new upload
   ↓
new stored object
   ↓
new metadata/version identity
   ↓
old object → trash
```

This preserves immutable stored objects and simplifies cache behavior.

---

## 15. Missing Physical Files

If metadata says a file exists but the physical object is missing:

- return HTTP 404 to the requester
- do not silently delete metadata
- log a `FILE_MISSING` event
- preserve the metadata for later reconciliation

The reverse inconsistency—physical object without metadata—should also be detectable later through an administrative health/reconciliation mechanism.

---

## 16. Security Architecture

Security is layered:

1. HTTPS
2. API-key authentication
3. project scoping
4. endpoint permissions
5. server-side file validation
6. dangerous-type rejection
7. filesystem protection
8. rate limiting
9. storage quotas
10. restrictive CORS where needed
11. CSRF protection for admin UI
12. safe error handling
13. security/event logging

Explicitly prohibited:

- executing uploaded files
- trusting client MIME type alone
- using `Origin` as authentication
- exposing API keys to browsers
- exposing raw filesystem paths
- exposing stack traces or SQL errors
- allowing direct access to trash/logs/configuration

---

## 17. File Type Policy

v1 uses a strict server-side inspection allowlist.

Supported Formats:

- **Images:** JPEG, PNG, WebP, GIF, AVIF, SVG, ICO, BMP
- **Documents:** PDF, TXT, CSV, JSON, Markdown, XML, RTF
- **Microsoft Office & OpenDocument:** Word (`.docx`, `.doc`), Excel (`.xlsx`, `.xls`), PowerPoint (`.pptx`, `.ppt`), OpenDocument (`.odt`, `.ods`, `.odp`)
- **Archives & Media:** ZIP, GZ, TAR, MP3, WAV, OGG, MP4, WebM, MOV

Security Controls:

- Executable & server-side formats (PHP, PHTML, PHAR, CGI, EXE, BAT, SH, JS, etc.) are strictly rejected.
- Macro-enabled Office extensions (`.docm`, `.xlsm`, `.pptm`, etc.) are prohibited to eliminate client-side macro execution risks.
- Double-extension attacks (e.g., `invoice.php.docx`) are intercepted and rejected.
- Physical stored files are saved by collision-resistant token without extensions, preventing direct execution by web servers.
- Server-side inspection (`finfo` / libmagic) is enforced alongside client metadata.

Validation must use server-side file inspection in addition to the client-provided filename/MIME information.

---

## 18. Rate Limits and Quotas

Controls exist at project level.

At minimum:

- maximum file size
- total storage quota
- request/upload rate limits

Future controls may include:

- hourly upload counts
- endpoint-specific limits
- bandwidth-aware controls

Public GET traffic can later be absorbed by a CDN.

---

## 19. CORS

CORS is an access-control mechanism for browser cross-origin requests, not authentication.

Project-specific allowed origins may be configured.

Avoid:

```text
Access-Control-Allow-Origin: *
```

for authenticated browser-facing API operations.

Direct public asset embedding does not require exposing project API credentials.

---

## 20. Error Handling

Standard error envelope:

```json
{
  "error": {
    "code": "internal_error",
    "message": "An internal error occurred.",
    "request_id": "req_..."
  }
}
```

Representative error codes:

```text
authentication_required
invalid_api_key
project_disabled
permission_denied
file_required
file_too_large
file_type_not_allowed
invalid_file
file_not_found
storage_error
rate_limit_exceeded
internal_error
```

Production responses must not expose:

- stack traces
- SQL errors
- local filesystem paths
- environment variables
- implementation details

---

## 21. CDN Strategy

FileVault remains the origin.

Conceptually:

```text
Browser
   ↓
CDN
   ↓
FileVault
```

The architecture is CDN-compatible because:

- public asset URLs are stable
- stored file identities are immutable
- replacement creates new file identities
- origin storage remains independent of public URL structure

No CDN-specific application coupling is required in v1.

---

## 22. Configuration

Secrets and deployment-specific configuration live outside source control.

Example:

```env
APP_ENV=production
APP_URL=https://files.example.com

DB_HOST=...
DB_NAME=...
DB_USER=...
DB_PASSWORD=...

STORAGE_ROOT=...

ADMIN_BOOTSTRAP_SECRET=...
```

`.env` must never be committed.

`.env.example` may document required variables without real secrets.

---

## 23. Git and Repository Hygiene

At minimum:

```gitignore
.env
.env.*
!.env.example

/storage/
/logs/
/tmp/
/cache/

*.log

/vendor/

.DS_Store
Thumbs.db
```

Never commit:

- API keys
- admin credentials
- uploaded files
- production logs
- database dumps
- temporary files
- real environment configuration

---

## 24. Backup and Recovery

Backups are intentionally outside v1.

Important distinction:

> Trash is retention for application-level deletion recovery; it is not a backup system.

Future backup architecture should protect both:

- filesystem objects
- MySQL metadata

A backup that contains only one side is incomplete.

---

## 25. Hosting Constraints

The architecture assumes a constrained shared-hosting environment.

Therefore v1 avoids:

- long-running workers
- Docker
- daemon processes
- Redis dependency
- mandatory Node runtime
- mandatory Python runtime
- VPS-only infrastructure
- complex queue infrastructure

Where scheduled work becomes necessary, Bluehost cron can be used.

---

## 26. Future Storage Migration

The storage boundary should allow implementations such as:

```text
StorageInterface
├── LocalFilesystemStorage   ← v1
├── S3Storage                ← future
├── R2Storage                ← future
└── B2Storage                ← future
```

The API and consuming applications should not need to know which backend is active.

---

## 27. Dependency Direction

Preferred dependency flow:

```text
HTTP/API
   ↓
Application Services
   ↓
Domain/Policies
   ↓
Repository / Storage Interfaces
   ↓
Infrastructure Implementations
```

Avoid:

```text
Controller → SQL
Controller → filesystem path
Controller → raw environment access
```

Cross-module access should occur through explicit contracts.

---

## 28. Module Dependency Graph

```text
                         M01 Core
                       /    |     \
                      /     |      \
                    M02    M05     M06
                     |      |       |
                     |      |       |
                    M03 ----M04-----+
                     \       |
                      \      |
                       M07---+
                        |
                  +-----+-----+
                  |           |
                 M08         M11
                  |
                 CDN

M09 Admin → M02, M04, M05, M11
M10 Security → M03, M04, M05, M02
M12 Operations → M01, M05, M06, M11
```

The graph is conceptual; final contracts may refine dependency direction.

---

## 29. Recommended Specification / Implementation Order

### Phase 1 — Foundations

1. M01 Core Foundation
2. M05 Data & Persistence
3. M06 Storage Backend

### Phase 2 — Identity and access

4. M04 Project Management
5. M03 Authentication & Authorization
6. M10 Security & Abuse Controls

### Phase 3 — File capabilities

7. M07 File Lifecycle
8. M08 Public File Delivery
9. M11 Observability & Errors

### Phase 4 — Management surface

10. M09 Admin Interface

### Phase 5 — Deployment

11. M12 Operations & Deployment
12. End-to-end integration review

The order is dependency-aware but does not require implementation to be completed strictly serially where contracts permit parallel work.

---

## 30. Architectural Decision Status

### DECIDED & IMPLEMENTED

- **Runtime & Deployment:** PHP 8.4+ running under native SAPI (Apache, Nginx, built-in CLI server) without heavy external framework bloat.
- **REST Transport & HTTP Layer (M02):** Full `/api/v1` implementation with custom lightweight Router, Request, Response, and Kernel abstractions.
- **Project API Authentication (M03):** High-entropy API key credentials (`fvk_<publicId>_<secret>`) with 32-byte SHA-256 hashed storage and constant-time verification.
- **Project Management (M04):** Authoritative quotas (`max_file_size`, `max_file_count`, `max_storage_bytes`), status transitions (`ACTIVE`, `RESTRICTED`, `DISABLED`), and usage tracking via `UsageDelta`.
- **Database & Persistence (M05):** Strict MySQL relational schemas with typed migrations (`projects`, `api_keys`, `files`), transaction boundaries, and immutable record mapping.
- **Storage Backend (M06):** Abstracted `StorageBackend` contract with `LocalFilesystemStorage` implementing partitioned storage keys (`YYYY/MM/<token>`) under `active/` and `trash/` directories.
- **File Lifecycle (M07):** Complete transactional lifecycle coordination for create, soft delete (trash), restore, permanent purge, and immutable replacement with quota synchronization.
- **Public File Delivery (M08):** Controlled public asset delivery under `/files/{storageKey}` and `/f/{id}` with streaming responses, strict MIME headers, and conditional HTTP caching (`ETag`, `304 Not Modified`).
- **Security & Type Validation (M10):** Allowlist-based MIME inspection, dangerous extension rejection (`.php`, scripts, executables), double-extension attack defense, and filename sanitization.
- **Observability & Error Envelopes (M11):** Universal JSON error envelope (`error: {code, message, request_id, details}`), centralized `ExceptionMapper`, and structured native PHP logging.
- **Admin Control Plane (M09):** Protected web administration dashboard (`/admin`) with session authentication (`ADMIN_PASSWORD`), CSRF protection, live metrics, project quota/status controls, and API key lifecycle management.
- **CLI Administration Tool:** Complete console tool (`bin/console.php`) for project creation, listing, API key generation, key revocation, and database migrations.
- **Interactive Client Interface:** Standalone web explorer (`client/index.php`) featuring drag-and-drop uploads, live gallery thumbnails, download actions, and quota monitoring.

### DEFERRED (Post-v1)

- Private signed download URLs (for authenticated non-public assets).
- Resumable/chunked multi-part uploads.
- Automatic background trash purging cron workers.
- Automated remote cloud backup sync.
- Cloud object-storage backend adapter (e.g. S3 / Cloudflare R2).

---

## 31. Architecture Change Rules

Any change that affects:

- module boundaries
- public API contracts
- authentication model
- storage abstraction
- project isolation
- file lifecycle semantics
- security boundaries
- persistence invariants

must be reviewed at architecture level before implementation.

Local implementation details that do not affect these boundaries may remain within the owning module.

---

## 32. Current Architectural State

**Implementation State:** COMPLETE & TESTED (Modules M01–M11).

All core architecture modules and management interfaces are fully implemented and verified with automated test suites passing 100% (222 tests, 549 assertions).

---

## 33. CLI Administration Console (`bin/console.php`)

Control-plane administration is executed via `bin/console.php` from the server command line:

```bash
# Run database migrations
php bin/console.php migrate

# Create a project with quota limits
php bin/console.php project:create "My Project" --max-file-size=50M --max-file-count=1000 --max-storage=1G

# List all registered projects and their storage usage
php bin/console.php project:list

# Generate a project API key (outputs plaintext credential once)
php bin/console.php key:create <projectId> "Primary Server Key"

# List API keys for a project
php bin/console.php key:list <projectId>

# Revoke an API key
php bin/console.php key:revoke <keyId>
```

---

## 34. Standalone Client Explorer Interface (`client/`)

A separate web interface is provided in `client/` for testing and managing file operations:

- **Isolated Configuration:** Reads server address and API key from its own `client/.env` file.
- **Server-to-Server Security:** API keys are never exposed to browser JavaScript; all API requests are proxied securely server-side.
- **Features:**
  - Real-time project status and storage quota progress bar.
  - Drag-and-drop file upload with custom name and visibility options.
  - Responsive file gallery rendering real-time thumbnails for uploaded images directly via FileVault's public URL.
  - Direct file download buttons and one-click public URL copying.
  - File deletion with instant vault synchronization.

---

## 35. Admin Web Management Interface (`/admin`)

An administrator interface is hosted directly under `/admin` on the primary FileVault server:

- **Authentication:** Protected by session authentication configured via `ADMIN_PASSWORD` and `ADMIN_USERNAME` in `.env`. Unauthenticated visitors are automatically redirected to `/admin/login`.
- **CSRF Defense:** State-changing operations (project creation, status updates, quota modification, key generation, and revocation) require cryptographic session CSRF tokens.
- **Project Governance:**
  - Create project buckets with granular quotas (`max_storage`, `max_files`, `max_file_size`).
  - Toggle project operational status (`ACTIVE`, `RESTRICTED`, `DISABLED`) on the fly.
  - Update storage quotas dynamically.
- **Key Lifecycle Management:**
  - Generate high-entropy project API keys with one-time plaintext credential display and single-click clipboard copying.
  - Immediately revoke compromised or deprecated keys.
- **Visual Design:** Glassmorphic dark aesthetic matching the FileVault design system, responsive card grids, live storage meters, and dedicated favicons (`favicon.svg`, `favicon.ico`).


