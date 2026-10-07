# FileVault Architecture

**Status:** PROPOSED — Architecture baseline  
**Document role:** System architecture and module boundary source of truth  
**Project:** FileVault  
**Architecture owner:** Architect  
**Last updated:** 2026-10-01

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

Initial conceptual entities:

### projects

```text
id
name
slug
status
storage_quota
max_file_size
created_at
updated_at
```

### api_keys

```text
id
project_id
key_hash
key_prefix
status
created_at
last_used_at
```

### files

```text
id
project_id
original_name
stored_name
storage_key
mime_type
size_bytes
visibility
status
created_at
updated_at
deleted_at
```

Additional fields may be introduced during module specification where justified.

### Key rule

File identity is independent of:

- consuming domain
- original filename
- physical directory layout

This allows public URL/domain changes and storage migrations without rewriting logical file identity.

---

## 9. Authentication & Authorization

### Project API

Project clients authenticate with an API key.

The key is:

- generated by FileVault
- shown securely at creation/rotation time
- stored hashed in FileVault
- stored in the consuming application's server-side environment
- never exposed to browser JavaScript

Authentication flow:

```text
API request
 → extract API key
 → hash/verify
 → resolve project
 → verify project status
 → establish authorization context
 → check endpoint permission
 → execute operation
```

### Admin

The admin UI uses browser session authentication.

Admin requests require:

- authenticated session
- CSRF protection for state-changing requests
- secure session handling

The admin system is not authenticated using project API keys.

---

## 10. API Architecture

Base path:

```text
/api/v1/
```

Initial conceptual endpoints:

```text
POST   /api/v1/files
GET    /api/v1/files/{id}
DELETE /api/v1/files/{id}
```

The exact endpoint contract belongs to the relevant module specification.

API versioning is contract versioning, not application release numbering.

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

v1 uses an allowlist.

Initial candidates:

- JPEG
- PNG
- WebP
- GIF
- AVIF
- PDF
- TXT
- CSV
- JSON
- ZIP

Office document formats may be enabled when a real consuming project requires them.

Executable/server-side formats such as PHP/PHTML/PHAR and similar script formats are rejected.

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

### DECIDED

- PHP + MySQL + Apache/HTTPS
- Bluehost/shared hosting as v1 deployment target
- REST-style API
- `/api/v1` versioning
- multi-project-capable architecture
- protected admin UI
- server-to-server API keys
- hashed API-key storage
- project-scoped authorization
- local filesystem storage in v1
- MySQL metadata
- generated stored filenames
- original filename as metadata
- one file per upload request
- public files in v1
- private visibility represented in schema
- soft deletion
- immutable replacement
- trash isolation
- allowlisted file types
- rate limiting and quotas
- generic JSON errors
- request IDs
- CDN-compatible architecture
- storage abstraction for future object storage

### PROPOSED

- Exact database columns beyond the conceptual model
- Exact filesystem key format
- Exact API endpoint schemas
- Exact rate-limit algorithm
- Exact CORS configuration model
- Exact admin session implementation
- Exact admin bootstrap mechanism
- Exact PHP framework/library choices, if any

### DEFERRED

- Private signed URLs
- Resumable/chunked uploads
- Automatic trash purge
- Automated backups
- Advanced audit UI
- File search/listing UI
- Object-storage backend
- Advanced CDN/WAF integration
- Large-scale multi-tenant administration

### OPEN

- Whether to use a PHP microframework or a deliberately lightweight custom HTTP layer
- Exact API-key format and rotation UX
- Exact storage quota accounting semantics
- Exact public URL shape
- Whether file metadata versions are represented as separate rows or explicit versions
- Exact reconciliation/health-check design

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

**Architecture baseline:** established.

The next architectural task is to turn each module into a formal module specification containing:

- responsibility
- scope
- public contracts
- inputs/outputs
- owned data
- dependencies
- invariants
- security requirements
- failure cases
- acceptance criteria
- integration requirements

No implementation should begin for a module until its specification is marked ready.

