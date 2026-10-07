# FileVault — M01 Core Foundation Module Specification

**Status:** PROPOSED  
**Module:** M01 — Core Foundation  
**Owner:** Module Owner — Core Foundation  
**Architectural Authority:** FileVault Architect  
**Implementation State:** Not started

## 1. Purpose

M01 establishes the shared application foundation required by every other FileVault module.

It provides the minimum infrastructure needed for the application to start safely, load and validate configuration, construct shared dependencies, and expose common primitives without owning business logic.

M01 is intentionally small. It must not become a dumping ground for unrelated utilities.

## 2. Responsibilities

M01 owns:

- Application bootstrap and startup sequence
- Environment/configuration loading
- Configuration normalization and validation
- Environment-specific configuration boundaries
- Dependency wiring / service construction
- Shared application context where genuinely necessary
- Application-wide infrastructure primitives with no clearer domain owner
- Base exception/error primitives
- Safe bootstrap failure behavior
- Required runtime initialization at the foundation level
- Request/application ID generation primitives if assigned here

## 3. Non-Responsibilities

M01 does **not** own:

- HTTP routing or controllers
- Authentication or API-key validation
- Authorization
- Project management
- Database schema or repositories
- File storage operations
- File validation or upload processing
- Public file delivery
- Admin authentication/UI
- Rate limiting
- Quota accounting
- CORS policy
- Business/domain rules
- Logging policy implementation
- Deployment automation
- Backup/recovery logic

If a feature has a meaningful domain owner, it should not be placed in M01 merely because multiple modules use it.

## 4. Architectural Position

M01 sits at the bottom of the FileVault dependency graph.

```text
                    Domain Modules
                 API/Auth/Files/etc.
                         │
                         ▼
                 M01 Core Foundation
              bootstrap/config/DI/etc.
                         │
                         ▼
                  PHP / Host Runtime
```

M01 must not depend on higher-level FileVault modules. Other modules may depend on M01.

## 5. Dependencies

### External/runtime

M01 may depend on:

- Supported PHP 8.x runtime
- Required PHP standard extensions
- Server-provided environment variables
- Filesystem access required for startup

### FileVault

M01 should have **no dependencies on other FileVault modules**.

Any apparent dependency on M02–M12 should be challenged during review.

## 6. Bootstrap Contract

The normal startup sequence should conceptually be:

```text
Process starts
    ↓
Load environment/configuration
    ↓
Normalize configuration
    ↓
Validate required configuration
    ↓
Initialize required runtime resources
    ↓
Construct shared infrastructure/services
    ↓
Create application runtime
    ↓
Hand control to HTTP/API or other entrypoint
```

Bootstrap failure must stop startup rather than allowing the application to run with invalid required configuration.

The exact mechanism remains open.

## 7. Configuration

Configuration must be centralized:

```text
Environment
    ↓
Configuration Loader
    ↓
Validated Configuration
    ↓
Application Services
```

M01 must support:

- Environment variables
- Required vs optional settings
- Safe defaults
- Type normalization
- Required-value validation
- Explicit application environment
- Secret handling without exposure

Potential configuration categories include:

```text
Application
- APP_ENV
- APP_DEBUG
- APP_URL

Database
- DB_HOST
- DB_NAME
- DB_USER
- DB_PASSWORD

Storage
- STORAGE_ROOT

API
- API_BASE_PATH
- MAX_FILE_SIZE

Security
- security-related secrets

CORS
- origin configuration

Rate limiting
- relevant limits

Logging
- destination/level
```

M01 owns loading and validation. The module owning a setting's behavior owns its semantics.

Examples:

- M06 owns what `STORAGE_ROOT` means.
- M10 owns rate-limit policy.
- M03 owns authentication configuration.
- M09 owns admin configuration.

## 8. Configuration Rules

1. Required production configuration must fail startup when missing or invalid.
2. Production secrets must never be hard-coded.
3. Development defaults must not silently become unsafe production defaults.
4. Boolean, integer, URL, and enum-like settings must be normalized and validated.
5. Configuration values must not appear in exception messages.
6. Secrets must never be logged.
7. `.env` may be supported for local development.
8. `.env.example` may document names but must contain no real secrets.
9. Configuration should be immutable after bootstrap where practical.
10. Runtime code should consume configuration through a defined interface/object rather than repeatedly reading environment state.

## 9. Dependency Wiring

M01 owns construction and wiring of shared application services.

Possible approaches:

- Lightweight manual dependency injection
- Small service container
- Framework-provided dependency injection

The smallest mechanism that preserves explicit dependencies, testability, predictable lifecycle, maintainability, and shared-hosting compatibility should be preferred.

M01 must not introduce a large framework solely to solve dependency wiring unless the broader architecture justifies it.

## 10. Shared Services and Utilities

A utility belongs in M01 only if:

1. Multiple modules genuinely need it.
2. It has no meaningful domain owner.
3. It is infrastructure-level rather than business logic.
4. Centralization prevents inconsistent duplication.

Possible examples:

- Configuration access
- Request/application ID primitive
- Base exception hierarchy
- Small infrastructure value primitives

Examples that belong elsewhere:

- Filename sanitization → file lifecycle/storage
- MIME/type validation → file lifecycle
- API-key hashing → authentication
- Project authorization → authorization
- Rate-limit calculation → security/abuse
- Quota accounting → project/security ownership
- File path construction → storage
- API response semantics → HTTP/API layer

## 11. Error / Exception Foundation

M01 may define base infrastructure exceptions.

It must not own every API error code.

Conceptually:

```text
Infrastructure exception
        ↓
Owning application layer
        ↓
API error response
```

Bootstrap/configuration failures may terminate startup. Domain failures remain owned by their respective modules.

M01 must avoid exposing:

- Filesystem paths
- Database credentials
- Environment values
- Stack traces
- Server internals

to end users.

## 12. Runtime Resources

M01 may perform required startup checks/initialization, but resource ownership remains with the module that uses the resource.

Potential runtime directories:

```text
storage/
logs/
tmp/
cache/
```

For example:

- M06 owns storage layout and storage-directory semantics.
- M11 owns log destinations.
- M01 may perform generic bootstrap checks where necessary.

M01 must not create an uncontrolled directory hierarchy merely because it can.

## 13. Environment Separation

At minimum, development and production behavior must be distinguishable.

Production should default toward:

- Debug disabled
- Safe error responses
- Secure cookie/session settings where applicable
- HTTPS expectations
- Restrictive security configuration
- No development diagnostics in HTTP responses

Development may enable diagnostics, but development-only behavior must be explicit.

Deployment should not depend on developers manually changing individual flags.

## 14. Application Lifecycle

M01 should provide a predictable lifecycle:

```text
BOOT
  ↓
VALIDATE
  ↓
INITIALIZE
  ↓
RUN
  ↓
FAIL SAFELY / SHUT DOWN
```

There should be one authoritative bootstrap path for normal application execution.

Future CLI/maintenance entrypoints should reuse the same foundation rather than reconstructing configuration independently.

No CLI framework is required in M01.

## 15. Security Requirements

M01 is part of the security boundary even though it does not own application security policy.

It must ensure:

- Secrets are not hard-coded.
- Secrets are not logged.
- Invalid required production configuration fails safely.
- Debug output cannot expose sensitive configuration.
- Configuration errors do not expose internal values.
- Runtime paths are not unnecessarily exposed.
- Bootstrap does not make sensitive directories publicly accessible by accident.
- Runtime permissions are compatible with the intended storage/security model.

M01 must not treat successful configuration loading as proof that the application is secure.

## 16. Failure Cases

### Missing required configuration

- Application does not start.
- Internal diagnostic is recorded through the available logging mechanism.
- External output, where applicable, is generic.

### Invalid configuration type

Example:

```text
MAX_FILE_SIZE=banana
```

Result: startup failure.

### Invalid enum/configuration value

Example:

```text
APP_ENV=banana
```

Result: startup failure.

### Unavailable required runtime resource

Result:

- Startup failure if essential.
- No partial healthy state.

### Insufficient permissions

Result:

- Startup failure when the affected resource is required.
- Safe diagnostic without exposing sensitive paths.

### Missing production secret

Result: startup failure.

### Missing optional configuration

Result:

- Explicit safe default, or
- Related optional feature disabled.

Never silently select unsafe behavior.

## 17. Request/Application ID

If request IDs are initialized at the foundation level, M01 provides only the generation/propagation primitive.

M11 owns logging and observability semantics.

M02 owns HTTP request/response integration.

The ID must be:

- Unique enough for operational correlation
- Safe to expose
- Non-secret
- Independent from database primary keys

Example:

```text
req_01J...
```

Exact format remains open.

## 18. Testing Requirements

M01 must be independently testable.

Tests should cover:

### Configuration

- Valid configuration loads.
- Required values are enforced.
- Invalid types are rejected.
- Invalid enum values are rejected.
- Safe defaults work.
- Production/debug separation works.
- Secrets are absent from normal diagnostics.

### Bootstrap

- Valid configuration starts successfully.
- Missing required configuration prevents startup.
- Required runtime resource failures prevent startup.
- Dependency construction is predictable.

### Dependency wiring

- Required services are available.
- Dependencies are explicit.
- No circular dependencies exist.

### Environment behavior

- Development diagnostics behave as intended.
- Production-safe behavior is enforced.

Tests must not require production secrets.

## 19. Integration Requirements

M01 must expose stable contracts for downstream modules.

Downstream modules need access to:

- Validated configuration
- Application bootstrap/runtime
- Dependency construction
- Approved shared primitives

A downstream module should conceptually request:

```text
"I need configuration X."
```

rather than directly reading `.env`.

## 20. Acceptance Criteria

M01 is accepted only when:

### Foundation

- [ ] A single authoritative bootstrap path exists.
- [ ] Startup is deterministic.
- [ ] Required configuration is validated before dependent services run.
- [ ] Configuration is centralized.
- [ ] Configuration types are normalized where required.
- [ ] Development and production behavior are distinguishable.
- [ ] Secrets are not hard-coded.
- [ ] Secrets are not logged.

### Dependency architecture

- [ ] M01 has no dependency on higher-level FileVault modules.
- [ ] Dependencies are explicitly wired.
- [ ] No circular dependency exists.
- [ ] Shared utilities have clear ownership.
- [ ] M01 is not a miscellaneous utility bucket.

### Failure behavior

- [ ] Missing required configuration prevents startup.
- [ ] Invalid configuration prevents startup.
- [ ] Required runtime resource failures are handled safely.
- [ ] Sensitive configuration is absent from user-facing errors.
- [ ] Production stack traces/debug details are not exposed.

### Runtime

- [ ] Required runtime resources can be initialized safely.
- [ ] Directory creation/checking follows module ownership.
- [ ] Future HTTP and CLI entrypoints can share the foundation.

### Testing

- [ ] Configuration tests exist.
- [ ] Bootstrap failure tests exist.
- [ ] Dependency wiring tests exist.
- [ ] Production-safe behavior is tested.

## 21. Open Questions

These must be resolved before implementation unless explicitly delegated:

1. PHP application structure: framework, microframework, or lightweight custom layer?
2. Configuration loader: native environment access, dotenv library, or another mechanism?
3. Dependency injection: manual constructor wiring vs lightweight container?
4. Exact base exception hierarchy and namespaces?
5. Request ID ownership: M01 generation vs M02 generation using an M01 primitive?
6. Which runtime directories must exist at bootstrap versus being created by their owning module?
7. How should fatal bootstrap configuration errors be reported before M11 is initialized?
8. Exact supported PHP 8.x minimum/target version?
9. Autoloading approach; Composer PSR-4 is expected but remains an implementation decision.
10. Exact environment variable naming conventions.

## 22. Architectural Decisions

### A-001 — Foundation remains dependency-light

M01 must not depend on higher-level business modules.

**Reason:** Every application entrypoint depends on the foundation.

### A-002 — Configuration is centralized

Application code must not independently read environment variables throughout the codebase.

**Reason:** Centralization provides validation, consistency, testability, and easier deployment changes.

### A-003 — M01 is not a utility dump

Shared code requires explicit ownership.

**Reason:** An unrestricted common module becomes a dependency sink.

### A-004 — Bootstrap failure is fail-closed

The application must not continue with invalid required configuration.

**Reason:** Partial initialization creates ambiguous and potentially unsafe behavior.

## 23. Dependency Handoff

M01 provides foundation contracts to:

- M02 HTTP/API Layer
- M03 Authentication & Authorization
- M04 Project Management
- M05 Data & Persistence
- M06 Storage Backend
- M07 File Lifecycle
- M08 Public File Delivery
- M09 Admin Interface
- M10 Security & Abuse Controls
- M11 Observability & Errors
- M12 Operations & Deployment

Primary handoff:

```text
M01
 ↓
validated configuration + application bootstrap
 ↓
all other modules
```

M01 should be implemented before downstream modules are considered implementation-ready.

## 24. Implementation Guidance

The implementer should:

1. Inspect the repository and existing project conventions.
2. Resolve approved open implementation decisions.
3. Build the smallest foundation satisfying this specification.
4. Avoid implementing downstream business logic.
5. Add tests for configuration/bootstrap behavior.
6. Document implementation decisions corresponding to OPEN items.
7. Flag architectural conflicts instead of silently resolving them in code.

The implementer must not expand M01 because another module is not yet specified.

## 25. Review Checklist

The Module Owner should verify:

- Does every M01 responsibility belong here?
- Is anything here actually owned by another module?
- Can downstream modules consume the foundation without knowing its implementation?
- Are secrets protected during startup and diagnostics?
- Does invalid required configuration reliably prevent startup?
- Is dependency direction correct?
- Has a common-utilities dumping ground been created?
- Can future HTTP and CLI entrypoints share bootstrap?
- Are open implementation choices recorded?
- Are acceptance criteria testable?

The Architect should additionally verify:

- M01 remains below business modules in the dependency graph.
- No module boundary has accidentally moved into M01.
- Bootstrap responsibilities fit the deployment architecture.
- The module does not introduce unnecessary framework/hosting complexity.

## 26. Readiness Status

**Current status: PROPOSED**

M01 becomes **READY FOR IMPLEMENTATION** when:

- Open implementation questions have approved answers or are explicitly delegated.
- Project coding conventions are established.
- Foundation contracts are accepted by the Architect.
- The Module Owner confirms that responsibilities, non-responsibilities, dependencies, security requirements, failure cases, and acceptance criteria are complete.

Until then, implementation should not begin.
