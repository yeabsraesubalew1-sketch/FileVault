# FileVault M02 - HTTP API Layer

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Http`  
**Architectural Role:** HTTP Front Controller, Request Routing, and API Endpoint Orchestration  

---

## 1. Overview & Purpose

The HTTP API Layer handles all incoming HTTP requests, performs URL routing, parses input payloads, enforces API-key authentication, executes controller actions, and formats HTTP responses.

M02 owns the public `/api/v1/*` REST API contract and ensures that transport-level concerns remain decoupled from domain models and persistence.

---

## 2. Directory Structure

```text
src/Http/
├── Kernel.php                       # HTTP front controller and middleware pipeline
├── Router.php                       # Regex-based route table and parameter matcher
├── Request.php                      # Encapsulates HTTP request data and headers
├── Response.php                     # HTTP status code, headers, and body emitter
├── CorsHandler.php                  # Preflight OPTIONS and CORS headers
└── Controller/
    ├── FileController.php           # File upload, list, download, trash, restore, purge
    ├── ProjectController.php        # Project metadata and quota status endpoint
    ├── PublicDeliveryController.php # Public asset streaming (/files/* and /f/*)
    └── AdminController.php          # Session-based administrative interface
```

---

## 3. Implemented Components & Contracts

### `Kernel` (`FileVault\Http\Kernel`)
The central request processing pipeline:
* `public function handle(Request $request): Response`:
  1. Evaluates CORS preflight via `CorsHandler`.
  2. Dispatches path to `Router`.
  3. If route requires authentication (`authRequired: true`), inspects `X-Api-Key` header and authenticates project scope via M03.
  4. Dispatches controller handler with parsed route parameters.
  5. Catches uncaught `Throwable` exceptions, delegates to `ExceptionMapper` (M11), logs the event, and returns a formatted JSON `ErrorEnvelope`.

### `Router` (`FileVault\Http\Router`)
Lightweight regex route matcher:
* Supports HTTP methods: `GET`, `POST`, `DELETE`, `OPTIONS`.
* Named wildcard parameters: `/api/v1/files/{id}` matches digits; `/files/{storageKey:.*}` matches nested slashes.
* Distinguishes between `404 Not Found` (route pattern does not exist) and `405 Method Not Allowed` (path exists but requested method is unsupported, returning the `Allow` header).

### `Request` (`FileVault\Http\Request`)
Encapsulates HTTP request context:
* `public static function capture(): self`: Reconstructs request state from PHP globals (`$_SERVER`, `$_GET`, `$_POST`, `$_FILES`).
* `public function header(string $name, ?string $default = null): ?string`
* `public function apiKey(): ?string`: Extracts API key from `X-Api-Key` or `Authorization: Bearer <key>`.
* Attached properties: `public ?AuthenticatedApiKey $authenticatedApiKey`, `public ?Project $authenticatedProject`.

### `Response` (`FileVault\Http\Response`)
Fluent HTTP response emitter:
* `public static function json(array $data, int $status = 200, array $headers = []): self`
* `public static function error(ErrorEnvelope $envelope, int $status, array $headers = []): self`
* `public static function html(string $html, int $status = 200): self`
* `public static function redirect(string $url, int $status = 302): self`
* `public function send(): void`: Sends HTTP status line, headers, and body to the web server.

---

## 4. REST API Endpoint Specification

All `/api/v1/*` endpoints require `X-Api-Key: <key>` header:

| Method | Endpoint | Handler | Action Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/health` | Closure | Returns system status and timestamp. |
| `GET` | `/api/v1/project` | `ProjectController::get` | Project name, quota limits, and storage usage. |
| `POST` | `/api/v1/files` | `FileController::upload` | Multipart file upload with quota check. |
| `GET` | `/api/v1/files` | `FileController::list` | Paginated list of active files (`limit`, `offset`). |
| `GET` | `/api/v1/files/{id}` | `FileController::get` | Single file metadata lookup. |
| `GET` | `/api/v1/files/{id}/download` | `FileController::download` | Stream download for private/trashed files. |
| `DELETE`| `/api/v1/files/{id}` | `FileController::delete` | Moves file to trash and reclaims quota. |
| `GET` | `/api/v1/files/trash` | `FileController::listTrash` | Paginated list of trashed files. |
| `POST` | `/api/v1/files/{id}/restore`| `FileController::restore` | Restores trashed file to active storage. |
| `POST` | `/api/v1/files/{id}/replace`| `FileController::replace` | Replaces file payload while keeping ID. |
| `DELETE`| `/api/v1/files/{id}/purge` | `FileController::purge` | Permanently deletes file record and physical blob. |

---

## 5. Invariants & Security Rules

1. **Strict Project Scoping**: Controllers only operate on records matching `$request->authenticatedProject->id`. Inquiring on files owned by another project returns `404 Not Found`.
2. **Standard Error Envelopes**: All error responses return `{ "error": { "code": string, "message": string, "request_id": string } }`.
3. **No Database Access in Controllers**: Controllers delegate domain operations to application services (`FileLifecycle`, `ProjectService`).
