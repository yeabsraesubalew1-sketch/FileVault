# FileVault M11 — Observability & Errors Module Specification

**Status:** PROPOSED — Architecture & Module Specification Proposal  
**Module:** M11 — Observability & Errors  
**Owner:** Observability Owner  
**Architectural Authority:** FileVault Architect  
**Implementation State:** NOT IMPLEMENTED (Specification Only)  
**Target Environment:** PHP 8.4.x, Apache, MySQL/MariaDB with PDO, Shared Hosting (Bluehost compatible)

---

## 1. Purpose

M11 establishes the shared application standards, conventions, and infrastructure primitives for operational observability and error management across FileVault.

It provides:
- A standardized, production-safe JSON error envelope for API consumers under `/api/v1`.
- Authoritative server-side Request-ID lifecycle, extraction, validation, and operational correlation conventions.
- Standardized error classification and sanitization rules that prevent internal diagnostic leaks (SQL queries, database credentials, server paths, stack traces).
- A minimal, robust native-PHP structured logging abstraction suitable for Apache/shared hosting environments without external vendor dependencies.
- Standardized recording conventions for security-relevant events and storage/lifecycle consistency anomalies.
- Safe handling conventions for unexpected exceptions and edge cases such as early bootstrap failures and mid-stream response failures.

M11 is intentionally small, explicit, and boring. It is **not** an enterprise telemetry framework, a distributed tracing system, a database audit log, or an event-driven messaging bus.

---

## 2. Ownership & Non-Goals

### 2.1 Owned Responsibilities
M11 owns:
1. **JSON Error Envelope Definition:** The canonical structure, serialization rules, and fallback encoding for `/api/v1` error responses.
2. **Error Classification Rules:** Mapping conventions defining how domain exceptions, validation failures, infrastructure errors, and unexpected runtime faults map to client-facing error codes and internal log severities.
3. **Request-ID Conventions:** Rules for generating, propagating, and logging authoritative server request identifiers (reusing M01 generator primitives).
4. **Structured Logging Standards:** Contract, log levels, formatting, context conventions, and redaction rules for internal logging via native PHP facilities.
5. **Security Event Recording Conventions:** Facade and standards for recording authentication anomalies, credential abuse, and security violations.
6. **Consistency Event Recording Conventions:** Facade and standards for recording metadata/storage divergence and lifecycle compensation failures.
7. **Production Sanitization Rules:** Absolute prohibition of sensitive information exposure in public-facing channels.

### 2.2 Explicit Exclusions & Non-Goals
M11 does **not** own:
- **HTTP Transport & Responses (M02):** M11 does not handle routing, inspect global HTTP request state directly, select HTTP status codes, send HTTP headers, or emit response bodies. M02 consumes M11 formatting helpers but retains full ownership of HTTP emission.
- **Authentication & Authorization Policy (M03):** M11 does not decide whether an API key is valid or revoked. M03 performs verification and throws domain exceptions; M02 catches those exceptions, maps them, and calls M11 logging conventions. M03 has zero dependency on M11.
- **Project Operational Policy & Quotas (M04):** M11 does not evaluate project status or quota headroom.
- **Persistence & Transactions (M05):** M11 does not own database tables, migrations, or database connection lifecycles. M11 does not store logs in MySQL.
- **Filesystem & Storage Operations (M06):** M11 does not construct filesystem paths, manage disk structures, or perform file I/O.
- **File Lifecycle Semantics (M07):** M11 does not execute file transitions, replacements, or compensations. M07 signals compensation failures via domain exceptions; M11 defines the logging convention.
- **Public Delivery Transport & Caching (M08):** M11 does not serve public assets or manage cache headers. M08 owns its transport response formats.
- **Admin Authentication & UI (M09):** M11 does not own administrative web sessions, CSRF tokens, or HTML error pages. M09 owns its HTML presentation.
- **Security Policy & Rate Limiting Mechanics (M10):** M11 does not enforce rate limits or evaluate dangerous file uploads.
- **Server Infrastructure & Deployment (M12):** M11 does not configure Apache virtual hosts, log rotation daemons, or OS syslog daemons.
- **Architectural Antipatterns:** M11 must not become a Service Locator, an Event Dispatcher/Bus, a generic middleware container, or a central dumping ground for miscellaneous utilities.

---

## 3. Existing Evidence & Upstream Dependencies

### 3.1 Locked Upstream Contracts (Verified)
Inspection of the current repository establishes the following locked baseline:
1. **M01 Foundation Primitives:**
   - `FileVault\Foundation\Support\RequestId`: `final readonly class RequestId { public function __construct(public string $value) {} public function __toString(): string {} }` (Lines 10-18 of `src/Foundation/Support/RequestId.php`).
   - `FileVault\Foundation\Support\RequestIdGenerator`: `final class RequestIdGenerator { public static function generate(): RequestId {} }` returning `req_` followed by 32 hex characters (Lines 10-22 of `src/Foundation/Support/RequestIdGenerator.php`).
   - `FileVault\Foundation\Config\Config`: Immutable configuration carrying `AppEnv $env`, `bool $debug`, `string $appUrl`, `DatabaseConfig $database`, `StorageConfig $storage` (`src/Foundation/Config/Config.php`).
2. **M03 Authentication:**
   - Throws `FileVault\ApiKey\Exception\ApiKeyAuthenticationException` with generic message `'Invalid API key credential.'` on all authentication failures (`src/ApiKey/Application/AuthenticateApiKey.php`).
   - Credential string is protected via `#[\SensitiveParameter]`.
3. **M04 Project Management:**
   - Throws `ProjectNotFoundException`, `ProjectNotOperationalException`, `PublicDeliveryNotAllowedException`, `QuotaExceededException` (with `public readonly array $violations`).
4. **M05 Persistence:**
   - Throws `ConnectionException`, `TransactionException`, `QueryException`, `ConstraintViolationException`.
5. **M06 Storage Backend:**
   - Physical filesystem paths are encapsulated inside `LocalFilesystemStorage`.
   - Callers only interact with opaque `storageKey` strings and PHP stream resources.
   - Throws `StorageException`, `StorageObjectNotFoundException`, `StorageConflictException`, `StorageOperationException`.
6. **M07 File Lifecycle:**
   - Coordinates M05 and M06; throws `InvalidLifecycleOperationException`, `LifecycleException`, and `LifecycleConsistencyException` (which carries `$cause` and `$compensationFailure`).

### 3.2 Specification Status
- Architecture baseline: **PROPOSED** (`docs/FileVault_architecture.md`).
- M01, M04, M05, M06, M07 specifications exist in `docs/`.
- M08 and M10 are not yet specified.
- M11 is currently represented only by architectural outline sections in `docs/FileVault_architecture.md` (lines 333-357, 711-750) and `docs/FileVault_module_ownership_plan.md` (lines 353-368).

---

## 4. Error Model & Envelope Specification

### 4.1 Canonical JSON Error Envelope
All error responses emitted by the REST API under `/api/v1` must conform strictly to the following top-level JSON structure:

```json
{
  "error": {
    "code": "quota_exceeded",
    "message": "Project quota exceeded.",
    "request_id": "req_a1b2c3d4e5f678901234567890abcdef",
    "details": null
  }
}
```

### 4.2 Envelope Field Definitions
1. `error` (`object`, required): The root wrapper object.
2. `error.code` (`string`, required):
   - Format: lowercase ASCII snake_case (`^[a-z][a-z0-9_]{2,63}$`).
   - Purpose: Machine-readable invariant error identifier for client programmatic branching.
   - Stability: Frozen once published.
3. `error.message` (`string`, required):
   - Format: Clean, concise, human-readable English sentence.
   - Constraint: Must be production-safe. Must never contain stack traces, database schema names, file paths, hostnames, or credentials.
4. `error.request_id` (`string`, required):
   - Value: The authoritative server request identifier (e.g., `req_01...`).
   - Purpose: Enables client developers to report issues and operators to trace logs without revealing system internals.
5. `error.details` (`object|null`, required):
   - Canonical representation: When no structured details exist, `details` must explicitly be `null`. It must **not** be omitted or represented as an empty array/object.
   - Purpose: Provides predictable schema deserialization for strongly typed client consumers and SDKs.
   - When present: A structured key-value map (e.g. `{"violations": ["file_size"]}` or `{"fields": {"name": "Required"}}`).
   - Constraint: Must follow strict validation schema. Never put raw exception stack traces or SQL dumps in `details`.

### 4.3 Standard Error Codes Registry & Ownership Convention
M11 defines the standard code formatting rules and baseline foundation codes. Domain modules define their specific domain codes adhering to this format. M02 maps these codes to HTTP status codes.

| Error Code | HTTP Status (Owned by M02) | Owning Module | Description | Decision Status |
|---|---|---|---|---|
| `internal_error` | 500 Internal Server Error | M11 | Unhandled exception, logic error, query execution bug, or compensation failure. | PROPOSED |
| `service_unavailable` | 503 Service Unavailable | M11 | Infrastructure outage (e.g. database connection refused during startup, storage volume offline). | PROPOSED |
| `invalid_request` | 400 Bad Request | M02 | Malformed JSON body, missing boundary, invalid query syntax. | PROPOSED |
| `not_found` | 404 Not Found | M02 | Route does not exist. | PROPOSED |
| `method_not_allowed` | 405 Method Not Allowed | M02 | HTTP verb not supported for the matched route. | PROPOSED |
| `authentication_required` | 401 Unauthorized | M02 / M03 | Missing `Authorization` / API key header. | PROPOSED |
| `invalid_api_key` | 401 Unauthorized | M03 | Key malformed, unknown, revoked, or secret verification failed. | VERIFIED (M03) |
| `project_disabled` | 403 Forbidden | M04 | Project status is `disabled`. | VERIFIED (M04) |
| `project_restricted` | 403 Forbidden | M04 | Project status is `restricted` (operation blocked). | VERIFIED (M04) |
| `project_not_found` | 404 Not Found | M04 | Project ID does not exist or access denied. | VERIFIED (M04) |
| `quota_exceeded` | 413 or 422 | M04 | Quota limits reached (`file_size`, `file_count`, `storage`). | UNRESOLVED (M02 status choice) |
| `file_not_found` | 404 Not Found | M05 / M07 | Logical file ID does not exist in project scope. | VERIFIED (M07) |
| `file_required` | 400 Bad Request | M07 | Upload request missing file payload stream. | PROPOSED |
| `file_too_large` | 413 Payload Too Large | M07 / M10 | File size exceeds project or server upload limits. | PROPOSED |
| `file_type_not_allowed` | 415 Unsupported Media Type | M10 | Prohibited MIME type or extension. | UNRESOLVED (M10 spec pending) |
| `invalid_lifecycle_transition`| 409 Conflict | M07 | Attempted invalid transition (e.g. restoring active file). | VERIFIED (M07) |
| `storage_error` | 500 Internal Server Error | M06 / M07 | Physical disk failure or I/O failure. | VERIFIED (M06) |
| `rate_limit_exceeded` | 429 Too Many Requests | M10 | Project or IP request rate limit exceeded. | UNRESOLVED (M10 spec pending) |

#### 4.3.1 Resolution of Specific Error Code Ambiguities
1. **`internal_error` vs `service_unavailable`:**
   - `internal_error` (500) represents an active system failure while handling an otherwise reachable system (e.g. unhandled `\Throwable`, SQL constraint violation, logic defect).
   - `service_unavailable` (503) represents an inability to reach essential external dependencies (e.g. MySQL server down, filesystem mount inaccessible).
2. **`quota_exceeded` HTTP Status:**
   - M11 defines the public error code as `quota_exceeded` with `details.violations` containing violated dimensions.
   - M02 owns the HTTP status mapping. Recommendation: M02 emits `413 Payload Too Large` when `violations` contains `file_size`, and `422 Unprocessable Entity` (or `403 Forbidden`) when aggregate project quota (`storage` or `file_count`) is exceeded. The final mapping decision is assigned to M02.
3. **Cross-Project Resource Access (404 Invariant):**
   - Security Invariant: Accessing a file or project that does not belong to the authenticated API key must return identical responses to non-existent resources (`404 Not Found`, code: `file_not_found` or `project_not_found`).
   - The API must **never** return `403 Forbidden` for a file belonging to another project, as doing so would leak file existence across tenant boundaries.

### 4.4 Transport Separation Policy
- **REST API (`/api/v1/*`):** Must **always** emit the JSON error envelope with `Content-Type: application/json; charset=utf-8`.
- **Public Delivery (`/files/*`, M08):** M08 owns its transport protocol. If a file is missing or ineligible, M08 returns standard HTTP status (e.g. `404 Not Found`) using minimal plain-text or empty bodies. M11 does not prescribe M08 response bodies.
- **Admin UI (`/admin/*`, M09):** M09 owns administrative HTML presentation. Failures render human-readable HTML error pages with embedded request IDs. M11 does not prescribe M09 HTML templates.

### 4.5 Serialization Safety & Fallback Behavior
The error envelope serialization utility (`ErrorEnvelope::toJson`) must guarantee safe output without ever throwing exceptions or leaking memory:
1. **Code/Message Sanitization:** If an empty or invalid code string is provided, `ErrorEnvelope` defaults to `internal_error`. If an empty message is provided, it defaults to `'An internal error occurred.'`.
2. **JSON Encoding Failure:** If `json_encode()` returns `false` (e.g. invalid UTF-8 bytes in `details`):
   - `ErrorEnvelope::toJson()` must **never** throw an unhandled exception or return an empty string.
   - It must fall back to a hardcoded constant JSON template:
     `{"error":{"code":"internal_error","message":"An internal error occurred.","request_id":"<safe_request_id>","details":null}}`
   - This prevents recursive error-handling loops in top-level exception handlers.

---

## 5. Exception Classification & Mapping Boundaries

### 5.1 Architecture Mapping Responsibilities
- **Domain Modules (M03–M07):** Throw expressive, typed domain exceptions describing domain failures (e.g. `QuotaExceededException`, `InvalidLifecycleOperationException`, `ApiKeyAuthenticationException`).
- **M11:** Defines classification categories, log severity mapping, and public envelope formatting utilities.
- **M02 (HTTP Transport):** Catches exceptions at the controller/kernel boundary, maps them through M11 classification, sets the HTTP response status code, and serializes the JSON response.

### 5.2 Exception Classification Matrix

| Category | Typical Exception Types | HTTP Status (M02) | Public Code (M11/Domain) | Log Level (M11) | Public Message Rule |
|---|---|---|---|---|---|
| **Authentication Failure** | `ApiKeyAuthenticationException` | 401 | `invalid_api_key` | NOTICE / WARNING | Standardized generic message: `"Invalid API key credential."` |
| **Project Policy Failure** | `ProjectNotOperationalException`, `PublicDeliveryNotAllowedException` | 403 | `project_disabled` / `project_restricted` | NOTICE | Safe explanation of project operational status. |
| **Resource Not Found** | `ProjectNotFoundException`, `ApiKeyNotFoundException` | 404 | `not_found` / `project_not_found` | NOTICE | Generic not found message without leaking cross-tenant data. |
| **Validation / Client Error** | `InvalidProjectNameException`, `InvalidApiKeyNameException` | 400 | `invalid_request` | INFO / NOTICE | Specific input validation error message. |
| **Quota Violation** | `QuotaExceededException` | 413 / 422 | `quota_exceeded` | NOTICE | Carries `details.violations` array. |
| **Lifecycle Conflict** | `InvalidLifecycleOperationException` | 409 | `invalid_lifecycle_transition` | NOTICE | Safe domain transition violation message. |
| **Physical Storage Error** | `StorageException`, `StorageOperationException` | 500 | `storage_error` | ERROR | Sanitized message: `"Storage operation failed."` |
| **Lifecycle Consistency Failure** | `LifecycleConsistencyException` | 500 | `internal_error` | CRITICAL | Sanitized message: `"An internal consistency error occurred."` |
| **Database Failure** | `ConnectionException`, `QueryException`, `PDOException` | 500 / 503 | `internal_error` / `service_unavailable` | ERROR / CRITICAL | Sanitized message: `"A database error occurred."` |
| **Unexpected Runtime Error** | `Throwable`, `Error`, `RuntimeException` | 500 | `internal_error` | ERROR | Sanitized message: `"An internal error occurred."` |

### 5.3 Streamed Response Failure Boundary
During large file downloads or streamed exports (M08/M02):
1. **Invariant:** Once HTTP response headers (`200 OK`) and initial body bytes have been sent over the wire, the response **cannot** be rolled back, converted to a 500 status, or wrapped in a JSON envelope.
2. **Mid-Stream Error Handling:**
   - Terminate the output stream immediately (abort connection).
   - Close all open file descriptors and stream handles cleanly in `finally` blocks.
   - Log an `ERROR` event containing the `request_id`, project ID, file/storage key, bytes delivered so far, and the underlying exception.
   - Do **not** attempt to append a JSON error envelope to the tail of a binary stream (which corrupts client downloads and leaks server internals).
   - The client connection is simply terminated, indicating transport-level failure to the consumer.

---

## 6. Request-ID Conventions & Propagation

### 6.1 Server-Authoritative Request ID
For v1, FileVault enforces a **server-authoritative** request ID policy:
1. **Generation:** Every incoming request receives a new, unique request ID generated by the server via M01's primitive:
   - Generator: `FileVault\Foundation\Support\RequestIdGenerator::generate()`
   - Type: `FileVault\Foundation\Support\RequestId`
   - Canonical Format: Strict `req_` prefix followed by 32 cryptographically secure hexadecimal characters (36 ASCII characters total, e.g. `req_a1b2c3d4e5f678901234567890abcdef`).
2. **Client Header Rejection for Server ID:** Clients are **not** permitted to dictate the server's authoritative request ID. Incoming client values are never adopted as the server's `RequestId`.

### 6.2 External Client Correlation Header (Optional Hint)
If an HTTP client transmits an external correlation header (`X-Correlation-Id` or `X-Client-Request-Id`):
1. **Validation Filter:**
   - Must match pattern: `^[A-Za-z0-9_-]{8,64}$`.
   - Any value exceeding 64 characters, containing whitespace, newlines, control characters, or non-whitelisted symbols is discarded immediately.
2. **Usage:**
   - A valid incoming correlation header is **never** used as the server's request ID.
   - It is stored solely as an auxiliary context field in log records (`context.client_correlation_id`) to assist client developers in correlating server logs with external gateway logs.
   - It is never returned in the `X-Request-Id` response header or `error.request_id` envelope.
3. **Rationale:** This policy prevents log injection, HTTP header splitting, cache collision in shared proxies, and guarantees 100% uniformity in server log indexing.

### 6.3 Emission & Output Propagation
For every request handled:
1. **HTTP Response Header:** M02 must emit `X-Request-Id: <value>` on all responses (success and error alike).
2. **JSON Error Envelope:** `error.request_id` must match the server's assigned `RequestId`.
3. **Structured Logs:** Every log entry produced during the request must include the request ID.

---

## 7. Logging Conventions & Architecture

### 7.1 Shared Hosting & Native Runtime Reality
FileVault is designed for environments including Apache on shared hosting (e.g. Bluehost) with PHP 8.4.
- Heavy external logging daemons, syslog sockets, or Elasticsearch clusters cannot be assumed.
- Logging to MySQL is prohibited for operational logs (database failures must not prevent error logging).
- Native PHP `error_log()` writing to the Web server error log or a local configured file inside `STORAGE_ROOT/logs` is the primary, robust sink.

### 7.2 Log Severity Levels
Adopting clean sequential integer thresholds for level filtering:

| Level | Severity Value | Description |
|---|---|---|
| `DEBUG` | 100 | Fine-grained diagnostic information for local development. Disabled in production. |
| `INFO` | 200 | Significant normal lifecycle milestones (e.g., project created, key generated). |
| `NOTICE` | 250 | Expected business rejections (invalid credentials, quota exceeded, invalid lifecycle request). |
| `WARNING` | 300 | Unusual conditions that do not halt operation (e.g., missing physical file during purge where metadata was successfully cleaned). |
| `ERROR` | 400 | Runtime failures preventing request completion (database query failure, storage I/O error, unhandled exception). Requires attention. |
| `CRITICAL` | 500 | Severe consistency anomalies or system-wide resource loss (e.g., physical compensation failure, database connection pool exhaustion). Immediate investigation required. |

`LogLevel::includes(LogLevel $other): bool` evaluates whether `$other->toInteger() >= $this->toInteger()`.

### 7.3 Structured Log Format
Logs must be formatted as single-line JSON strings to guarantee atomicity and easy parsing:

```json
{
  "timestamp": "2026-10-12T00:15:30.123456Z",
  "level": "ERROR",
  "request_id": "req_a1b2c3d4e5f678901234567890abcdef",
  "message": "Storage write operation failed.",
  "channel": "storage",
  "context": {
    "project_id": 17,
    "storage_key": "p17/abc123def456"
  },
  "exception": {
    "class": "FileVault\\Storage\\Exception\\StorageOperationException",
    "message": "Disk write failed: No space left on device",
    "code": 0,
    "file": "/path/to/src/Storage/Local/LocalFilesystemStorage.php",
    "line": 142
  }
}
```

### 7.4 Sensitive Data Prohibitions & Redaction Rules
Key-name redaction alone is insufficient. FileVault enforces strict data minimization:
1. **Absolute Logging Prohibitions:** Under no circumstances may the following ever be written to logs:
   - Plaintext API keys (e.g. `fvk_...`).
   - `Authorization` header contents.
   - Passwords or database credentials.
   - Raw cookies or session identifiers.
   - Uploaded file contents or binary stream payloads.
   - Complete unparsed HTTP request bodies.
2. **Context Key Redaction:** Context array traversal automatically masks any key matching:
   - `password`, `secret`, `secretHash`, `rawSecretBytes`, `plaintextCredential`, `api_key`, `token`, `authorization`, `cookie`, `db_password`.
   - Mask value: `'[REDACTED]'`.
3. **Exception Message Caution:** Exception messages can inadvertently contain query strings or user input. Exception messages logged internally must be sanitized, and must **never** be reflected to public error envelopes.
4. **Log Disclosure Policy:** Internal logs are protected administrative diagnostics and must **never** be disclosed via public API endpoints or reflected back to project API consumers.

### 7.5 Logger Instance Immutability
To prevent context leakage between requests or asynchronous coroutines:
- `StructuredLogger::withRequestId(RequestId $requestId): self` and `StructuredLogger::withChannel(string $channel): self` must be **immutable**.
- They must return a **new instance** with the updated context, preserving the original logger untouched.

### 7.6 Recursive Failure Prevention
If writing to the log destination fails (e.g., disk full or permission error):
- The logger must **never** throw an exception that crashes the application or triggers recursive error logging.
- It must catch the internal write exception and attempt a minimal fallback to PHP's built-in `error_log('FileVault Log Failure: ' . $message, 0)`.
- If that fallback also fails, it must fail silently.

---

## 8. Early-Bootstrap Failure Boundary

A minimal early-bootstrap failure boundary is required in `public/index.php` for errors occurring before normal application services are constructed:
1. **Isolated Execution:** When `App::bootstrap($basePath)` fails, the runtime must **not** attempt to load database connections, repositories, or higher-level services.
2. **Emergency Request ID:** Generate an emergency request ID via `RequestIdGenerator::generate()`. If class loading failed, fall back to `new RequestId('req_' . bin2hex(random_bytes(16)))`.
3. **Best-Effort Logging:** Log the failure directly via native `error_log()`:
   ```text
   [FileVault Bootstrap Failure] [req_...] <ExceptionClass>: <Message> in <File>:<Line>
   ```
4. **Safe Response Emission:**
   - CLI: Print sanitized error message to STDERR.
   - HTTP: Set HTTP 500 (or 503 if database connection refused), set `Content-Type: application/json`, emit `X-Request-Id: <req_...>`, and output a minimal static JSON payload:
     ```json
     {"error":{"code":"service_unavailable","message":"Service unavailable.","request_id":"req_...","details":null}}
     ```
   - Exit immediately with code 1.
5. **No Duplicate Handlers:** Once `App::bootstrap()` succeeds, M02's kernel assumes complete ownership of all request lifecycle and exception mapping.

---

## 9. Security & Consistency Events

### 9.1 Architecture & Calling Boundaries
Security and consistency event recording are defined as explicit logging facades, **not** an event bus, queue, or durable database audit store.
- **Calling Boundary:** Domain modules (M03, M07) throw domain exceptions or return domain results. The outer calling boundary (M02 or an application use-case coordinator) catches these events and invokes `EventLogger`.
- **M03 Independence:** M03 does **not** depend on M11. When M03 throws `ApiKeyAuthenticationException`, M02 catches it and records the security event via M11.
- **M07 Independence:** When M07 throws `LifecycleConsistencyException`, the calling use case or M02 catches it and records the consistency event via M11.

### 9.2 Security Event Recording
- Event Types:
  - `auth.invalid_key`: Attempt to access `/api/v1` with an unknown or revoked key.
  - `auth.malformed_credential`: Attempt with unparseable credential string.
  - `project.unauthorized_access`: Attempt to perform operations on a project not owned by the authenticated key.
  - `project.disabled_access`: Attempt to access a disabled project.
- Severity: Logged at `NOTICE` or `WARNING` level with structured tag `event_type: "security.*"`.
- Content: Records `event_type`, `request_id`, client IP (from M02), and public key ID (if parseable). Never logs the secret credential.

### 9.3 Consistency Event Recording
- Event Types:
  - `consistency.orphan_physical_file`: Physical file stored, database creation failed, and physical compensation purge failed.
  - `consistency.metadata_without_physical`: Database file record exists, but physical object is missing on disk.
  - `consistency.compensation_failed`: Lifecycle rollback failed during replace/trash/restore.
- Severity: Logged at `CRITICAL` level with structured tag `event_type: "consistency.*"`.
- Content: Records `event_type`, `request_id`, `project_id`, `file_id`, `storage_key`, and details of both primary failure and compensation failure.
- V1 Reality: No automated background reconciliation service or external paging system exists in v1. Logging consistency events at `CRITICAL` severity guarantees an immutable audit trail in server logs for manual operator inspection.

---

## 10. Proposed Public Contracts

All proposed contracts reside under the namespace `FileVault\Observability`.

### 10.1 `ErrorEnvelope`

```php
namespace FileVault\Observability;

use FileVault\Foundation\Support\RequestId;

final readonly class ErrorEnvelope
{
    /**
     * @param string $code Machine-readable snake_case code.
     * @param string $message Safe human-readable message.
     * @param RequestId|string $requestId Server request identifier.
     * @param array<string, mixed>|null $details Optional structured details, or null.
     * @return array{error: array{code: string, message: string, request_id: string, details: array<string, mixed>|null}}
     */
    public static function create(
        string $code,
        string $message,
        RequestId|string $requestId,
        ?array $details = null
    ): array;

    /**
     * Serializes error array to UTF-8 JSON with zero-throw fallback guarantees.
     *
     * @param string $code
     * @param string $message
     * @param RequestId|string $requestId
     * @param array<string, mixed>|null $details
     * @return string
     */
    public static function toJson(
        string $code,
        string $message,
        RequestId|string $requestId,
        ?array $details = null
    ): string;
}
```

### 10.2 `LogLevel` Enum

```php
namespace FileVault\Observability;

enum LogLevel: string
{
    case DEBUG = 'DEBUG';
    case INFO = 'INFO';
    case NOTICE = 'NOTICE';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
    case CRITICAL = 'CRITICAL';

    public function toInteger(): int
    {
        return match ($this) {
            self::DEBUG => 100,
            self::INFO => 200,
            self::NOTICE => 250,
            self::WARNING => 300,
            self::ERROR => 400,
            self::CRITICAL => 500,
        };
    }

    public function includes(LogLevel $other): bool
    {
        return $other->toInteger() >= $this->toInteger();
    }
}
```

### 10.3 `LoggerInterface`

```php
namespace FileVault\Observability;

use Throwable;

interface LoggerInterface
{
    public function log(
        LogLevel $level,
        string $message,
        array $context = [],
        ?Throwable $exception = null
    ): void;

    public function debug(string $message, array $context = [], ?Throwable $exception = null): void;
    public function info(string $message, array $context = [], ?Throwable $exception = null): void;
    public function notice(string $message, array $context = [], ?Throwable $exception = null): void;
    public function warning(string $message, array $context = [], ?Throwable $exception = null): void;
    public function error(string $message, array $context = [], ?Throwable $exception = null): void;
    public function critical(string $message, array $context = [], ?Throwable $exception = null): void;
}
```

### 10.4 `StructuredLogger`

```php
namespace FileVault\Observability;

use FileVault\Foundation\Support\RequestId;
use Throwable;

final class StructuredLogger implements LoggerInterface
{
    public function __construct(
        private readonly string $channel = 'app',
        private readonly LogLevel $minLevel = LogLevel::INFO,
        private readonly ?string $logFilePath = null,
        private readonly ?RequestId $requestId = null
    ) {}

    /**
     * Returns a new instance with the specified RequestId (immutable).
     */
    public function withRequestId(RequestId $requestId): self
    {
        return new self($this->channel, $this->minLevel, $this->logFilePath, $requestId);
    }

    /**
     * Returns a new instance with the specified channel (immutable).
     */
    public function withChannel(string $channel): self
    {
        return new self($channel, $this->minLevel, $this->logFilePath, $this->requestId);
    }

    public function log(LogLevel $level, string $message, array $context = [], ?Throwable $exception = null): void;
    public function debug(string $message, array $context = [], ?Throwable $exception = null): void;
    public function info(string $message, array $context = [], ?Throwable $exception = null): void;
    public function notice(string $message, array $context = [], ?Throwable $exception = null): void;
    public function warning(string $message, array $context = [], ?Throwable $exception = null): void;
    public function error(string $message, array $context = [], ?Throwable $exception = null): void;
    public function critical(string $message, array $context = [], ?Throwable $exception = null): void;
}
```

### 10.5 `EventLogger`

```php
namespace FileVault\Observability;

use FileVault\Foundation\Support\RequestId;
use Throwable;

final class EventLogger
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function recordSecurityEvent(
        string $eventType,
        RequestId $requestId,
        array $context = []
    ): void;

    public function recordConsistencyEvent(
        string $eventType,
        RequestId $requestId,
        array $context = [],
        ?Throwable $failure = null
    ): void;
}
```

---

## 11. Configuration Specification

M11 configuration settings are identified below. Integrating them into `FileVault\Foundation\Config\Config` is a proposed future update for M01; M01 code and specifications are not modified in this task.

### 11.1 Proposed Settings Definition

| Setting Name | Type | Default | Allowed Values | Description | Decision Status |
|---|---|---|---|---|---|
| `LOG_LEVEL` | string | `info` | `debug`, `info`, `notice`, `warning`, `error`, `critical` | Minimum logging threshold. In testing/dev, defaults to `debug`. | PROPOSED |
| `LOG_DESTINATION` | string | `error_log` | `error_log` or secure file path | Destination sink. If file path, must reside outside public web root (e.g. in `STORAGE_ROOT/logs`). | PROPOSED |

### 11.2 Validation Rules
- `LOG_LEVEL`: Case-insensitive match against `LogLevel` enum.
- `LOG_DESTINATION`: If `error_log`, writes via PHP `error_log()`. If path, must be within designated storage logs directory and writeable.
- No secrets are involved in M11 configuration.

---

## 12. Failure Behavior & Edge Cases

1. **Expected Domain Exception (e.g. Quota Exceeded):**
   - M02 catches `QuotaExceededException`.
   - Logger logs a `NOTICE` event.
   - M02 maps status code to 413 or 422 and renders `ErrorEnvelope::toJson('quota_exceeded', 'Project quota exceeded.', $requestId, ['violations' => $e->violations])`.
2. **Unexpected Exception (e.g. Database Query Failure):**
   - M02 top-level try/catch catches `\Throwable`.
   - Logger logs an `ERROR` event with sanitized message, class name, and request ID.
   - M02 returns HTTP 500 with `ErrorEnvelope::toJson('internal_error', 'An internal error occurred.', $requestId, null)`.
   - No diagnostic details, SQL queries, or file paths are exposed to the client.
3. **Mid-Stream Response Failure:**
   - Stream transfer encounters an I/O failure after HTTP 200 headers are sent.
   - Abort stream; close resource handles in `finally` blocks.
   - Logger logs an `ERROR` event with byte counts and request ID.
   - Do **not** send a JSON error envelope.
4. **Log Destination Failure:**
   - Logger file handle write fails or disk is full.
   - Caught internally by `StructuredLogger`. Fallback to `error_log()`.
   - If fallback fails, fail silently without disrupting application execution.

---

## 13. Testing Strategy & Acceptance Criteria

### 13.1 Testing Strategy (For Future Implementation)
When M11 is authorized for implementation, tests must include:
1. `ErrorEnvelopeTest`:
   - Validates JSON structure matches schema exactly (`error.code`, `error.message`, `error.request_id`, `error.details`).
   - Asserts `"details": null` is present when no details exist.
   - Asserts fallback to safe constant JSON on encoding failure.
2. `RequestIdPolicyTest`:
   - Validates that server generates authoritative `req_<hex32>` ID.
   - Validates that client-provided IDs are never adopted as server request IDs.
   - Validates that valid external correlation IDs are sanitized and placed in log context.
3. `StructuredLoggerTest`:
   - Tests log formatting, single-line JSON encoding, and level filtering.
   - Tests immutability of `withRequestId()` and `withChannel()`.
   - Tests automatic redaction of sensitive context keys (`secret`, `password`, `rawSecretBytes`).
   - Tests logger fallback behavior when file writes fail.
4. `EventLoggerTest`:
   - Verifies security events and consistency events format structured logs correctly with appropriate tags and severity.

### 13.2 Acceptance Criteria
- [ ] No external logging dependencies or frameworks introduced.
- [ ] M01 `RequestId` and `RequestIdGenerator` are consumed without duplication.
- [ ] Server generates authoritative request ID for every request; client values are not adopted as server request ID.
- [ ] Canonical JSON error envelope always includes `"details": null` when no details exist.
- [ ] Sensitive context fields are automatically redacted; raw passwords/tokens are never logged.
- [ ] Stack traces, SQL errors, and filesystem paths are never leaked to public error envelopes.
- [ ] `StructuredLogger::withRequestId()` and `withChannel()` are verified immutable.
- [ ] Streamed response error boundary rules are documented and verified.
- [ ] Early-bootstrap failure boundary is documented and isolated from failed services.
- [ ] Working tree remains clean; no runtime implementation created during specification phase.

---

## 14. Open Decisions

1. **`quota_exceeded` HTTP Status Assignment:** M02 must finalize whether quota rejections emit `413 Payload Too Large` (for file size) and `422 Unprocessable Entity` or `403 Forbidden` (for aggregate quota). (Assigned to M02).
2. **Log File Storage Root Integration:** Confirm whether `LOG_DESTINATION` path default is resolved relative to `STORAGE_ROOT/logs` once M01 configuration is updated. (Assigned to M01/M11 integration).
