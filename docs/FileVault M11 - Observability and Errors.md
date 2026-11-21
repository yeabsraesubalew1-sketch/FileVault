# FileVault M11 - Observability and Errors

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Observability`  
**Architectural Role:** Standardized Error Envelopes, Exception Mapping, Request Tracing, and Logging  

---

## 1. Overview & Purpose

The Observability and Errors module provides unified error handling, exception-to-HTTP mapping, structured disk logging, and distributed request tracing for FileVault.

It guarantees that internal database exceptions, PDO errors, and disk paths are never exposed to external clients in production, while providing developers with actionable correlation identifiers (`request_id`).

---

## 2. Directory Structure

```text
src/Observability/
├── ErrorEnvelope.php                        # Standard JSON error data transfer object
├── ExceptionMapper.php                      # Maps domain exceptions to HTTP status codes & error codes
└── Logger.php                               # Structured disk logging utility
```

---

## 3. Implemented Components & Contracts

### `ErrorEnvelope` (`FileVault\Observability\ErrorEnvelope`)
The uniform JSON error representation returned across all FileVault API endpoints:
```json
{
  "error": {
    "code": "quota_exceeded",
    "message": "File size exceeds available project quota.",
    "request_id": "req_8a1f2b3c4d5e"
  }
}
```
* `public readonly string $code`: Stable machine-readable error identifier string.
* `public readonly string $message`: Clear human-readable description.
* `public readonly string $requestId`: Unique request trace identifier.
* `public readonly array $context`: Optional sanitised metadata (omitted in production).

### `ExceptionMapper` (`FileVault\Observability\ExceptionMapper`)
Translates domain exceptions to HTTP responses:
* `public static function map(Throwable $e, string $requestId, bool $debug): array`:
  Returns array containing `status` (HTTP status code), `envelope` (`ErrorEnvelope`), and `logLevel`.

#### Status Code & Error Code Mapping Matrix:
| Exception Class | HTTP Status | Error Code | Log Level |
| :--- | :--- | :--- | :--- |
| `ApiKeyAuthenticationException` | `401 Unauthorized` | `invalid_api_key` | `WARNING` |
| `ProjectNotOperationalException`| `403 Forbidden` | `project_disabled` | `WARNING` |
| `QuotaExceededException` | `403 Forbidden` | `quota_exceeded` | `INFO` |
| `ProjectNotFoundException` | `404 Not Found` | `project_not_found` | `INFO` |
| `StorageObjectNotFoundException`| `404 Not Found` | `file_not_found` | `INFO` |
| `StorageConflictException` | `409 Conflict` | `conflict` | `WARNING` |
| `InvalidArgumentException` | `400 Bad Request` | `validation_failed` | `INFO` |
| `LifecycleException` | `500 Internal Error` | `storage_error` | `ERROR` |
| `DatabaseException` / `PDOException` | `500 Internal Error` | `database_error` | `ERROR` |
| Generic `Throwable` | `500 Internal Error` | `internal_error` | `ERROR` |

### `Logger` (`FileVault\Observability\Logger`)
File-based structured logging writing to `storage/logs/filevault.log`:
* `public function log(string $level, string $message, array $context = []): void`: Writes timestamped, level-tagged log records containing `request_id`, method, path, and sanitized exception details.

---

## 4. Key Invariants & Production Safety

1. **Information Leakage Prevention**: When `APP_DEBUG=false`, internal database error strings, SQL queries, and local file paths are stripped from error responses and replaced with generic safe explanations (`"An internal error occurred."`).
2. **Correlation Guaranteed**: Every error envelope includes a non-null `request_id` that directly matches the server-side log entry.
