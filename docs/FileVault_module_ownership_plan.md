# FileVault Module Ownership & Delivery Plan

**Status:** PROPOSED — Planning baseline  
**Project:** FileVault  
**Depends on:** `docs/architecture.md`

---

## 1. Purpose

This document turns the FileVault architecture into an actionable module ownership and delivery plan.

It defines:

- module ownership
- architectural accountability
- dependency order
- specification order
- implementation grouping
- cross-module handoff points
- readiness requirements

It does **not** replace individual module specifications.

---

## 2. Ownership Model

Each module has one **Module Owner**.

The Module Owner is accountable for:

- defining the module's responsibility
- defining its boundaries
- defining its contracts
- identifying dependencies
- defining owned data and invariants
- identifying security concerns
- defining failure behavior
- writing acceptance criteria
- reviewing the implementation
- confirming readiness for integration

The Implementer may be a different person or agent.

Delegating implementation does **not** delegate ownership.

The Architect remains responsible for:

- system-wide coherence
- cross-module contracts
- dependency conflicts
- security boundary conflicts
- changes affecting architectural decisions

---

## 3. Module Ownership Matrix

| ID | Module | Owner Role | Primary Responsibility | Depends On | Spec Priority |
|---|---|---|---|---|---:|
| M01 | Core Foundation | Foundation Owner | Bootstrap/config/shared infrastructure | — | 1 |
| M05 | Data & Persistence | Persistence Owner | Database/schema/repositories | M01 | 2 |
| M06 | Storage Backend | Storage Owner | Storage abstraction/filesystem | M01 | 2 |
| M04 | Project Management | Project Owner | Projects/configuration/quotas | M01, M05 | 3 |
| M03 | Authentication & Authorization | Identity Owner | API keys/authz/project scope | M01, M04, M05 | 4 |
| M10 | Security & Abuse Controls | Security Owner | Rate limits/CORS/security policy | M02, M03, M04, M05 | 5 |
| M07 | File Lifecycle | File Owner | Upload/validation/replacement/deletion | M02, M03, M04, M05, M06 | 6 |
| M08 | Public File Delivery | Delivery Owner | Public retrieval/URL delivery | M05, M06 | 7 |
| M11 | Observability & Errors | Observability Owner | Errors/logging/request tracing | M01, M02, M05 | 7 |
| M02 | HTTP/API Layer | API Owner | Routing/controllers/API contract | M01 | 3* |
| M09 | Admin Interface | Admin Owner | Admin UI/session/management | M02, M04, M05, M11 | 8 |
| M12 | Operations & Deployment | Operations Owner | Hosting/deployment/runtime | M01, M05, M06, M11 | 9 |

`*` M02 can be specified alongside M04/M05/M06 after M01 establishes the application bootstrap conventions.

---

## 4. Architect vs Module Owner

### Architect

Owns:

- architecture document
- architecture decisions
- module boundaries
- dependency direction
- cross-module contracts
- system invariants
- architectural change approval

The Architect does **not** need to micromanage internal implementation.

### Module Owner

Owns:

- module specification
- module contract
- module-specific invariants
- module edge cases
- acceptance criteria
- implementation review
- integration readiness

### Implementer

Owns:

- implementing the approved specification
- following project governance
- self-review
- reporting implementation conflicts or specification gaps

### Reviewer / Integrator

Owns:

- verifying the implementation satisfies the specification
- checking integration compatibility
- identifying regressions
- confirming project-level readiness

---

## 5. Module Boundaries and Ownership Details

### M01 — Core Foundation

**Owner:** Foundation Owner

Owns:

- application bootstrap
- configuration loading
- environment access
- dependency wiring
- common primitives
- request ID generation boundary
- application lifecycle

Does not own:

- SQL queries
- file movement
- authentication policy
- HTTP endpoint business logic

**Primary output:** stable application foundation used by all other modules.

---

### M02 — HTTP/API Layer

**Owner:** API Owner

Owns:

- route registration
- `/api/v1`
- controller boundaries
- request parsing
- response serialization
- HTTP status mapping
- API versioning

Does not own:

- API-key validation logic
- database queries
- filesystem operations
- file lifecycle business rules

**Primary output:** stable transport layer for API consumers.

---

### M03 — Authentication & Authorization

**Owner:** Identity Owner

Owns:

- API-key verification
- key hashing
- project resolution
- project status checks
- permissions
- authorization context

Does not own:

- project creation
- file creation
- admin login

**Primary output:** authenticated, project-scoped execution context.

---

### M04 — Project Management

**Owner:** Project Owner

Owns:

- project creation
- project disabling
- project configuration
- quotas
- file-size limits
- project-level settings

Does not own:

- authentication implementation
- filesystem implementation
- admin UI presentation

**Primary output:** authoritative project configuration.

---

### M05 — Data & Persistence

**Owner:** Persistence Owner

Owns:

- database connection
- schema
- migrations
- repositories
- transaction primitives
- persistence mapping

Does not own:

- business decisions about file lifecycle
- authentication policy
- UI

**Primary output:** reliable persistence contracts.

---

### M06 — Storage Backend

**Owner:** Storage Owner

Owns:

- storage interface
- local filesystem implementation
- storage keys
- physical writes
- physical moves
- existence checks
- trash operations

Does not own:

- database metadata
- HTTP responses
- API authorization

**Primary output:** backend-independent storage contract with a local filesystem implementation.

---

### M07 — File Lifecycle

**Owner:** File Owner

Owns:

- upload orchestration
- validation policy
- file identity
- metadata lifecycle
- replacement
- deletion
- orphan handling
- lifecycle invariants

This is one of the central business modules.

**Primary output:** complete file lifecycle service.

---

### M08 — Public File Delivery

**Owner:** Delivery Owner

Owns:

- public retrieval
- public URL resolution
- content-type response behavior
- caching headers
- missing-file handling

Does not own:

- file creation
- API authentication
- internal filesystem path construction

**Primary output:** safe, stable public asset delivery.

---

### M09 — Admin Interface

**Owner:** Admin Owner

Owns:

- administrator login
- sessions
- CSRF
- admin pages
- project management UI
- API-key management UI

Does not own the underlying project/key business rules.

**Primary output:** protected administrative control surface.

---

### M10 — Security & Abuse Controls

**Owner:** Security Owner

Owns:

- rate limiting
- quota enforcement mechanisms
- CORS policy
- security headers
- dangerous file-type policy
- abuse controls

Security is cross-cutting, so this owner collaborates with every resource-owning module.

**Primary output:** enforceable security policies and mechanisms.

---

### M11 — Observability & Errors

**Owner:** Observability Owner

Owns:

- error envelope
- request IDs
- logging
- security events
- storage inconsistency events
- safe production error responses

**Primary output:** consistent failure behavior and operational visibility.

---

### M12 — Operations & Deployment

**Owner:** Operations Owner

Owns:

- Bluehost deployment
- Apache configuration
- filesystem permissions
- environment setup
- production deployment procedure
- maintenance procedures
- cron integration when required

**Primary output:** reproducible production deployment.

---

## 6. Specification Waves

The modules should not all be specified at once.

### Wave A — Foundation Contracts

Specify:

1. M01 — Core Foundation
2. M05 — Data & Persistence
3. M06 — Storage Backend

These establish the fundamental infrastructure contracts.

---

### Wave B — Identity and Transport

Specify:

4. M02 — HTTP/API Layer
5. M04 — Project Management
6. M03 — Authentication & Authorization

These define how a project reaches FileVault and becomes an authorized actor.

---

### Wave C — Cross-Cutting Controls

Specify:

7. M10 — Security & Abuse Controls
8. M11 — Observability & Errors

These can be finalized once the API and identity contracts are sufficiently concrete.

---

### Wave D — File Capabilities

Specify:

9. M07 — File Lifecycle
10. M08 — Public File Delivery

These depend on the earlier contracts and contain the main user-facing storage behavior.

---

### Wave E — Management and Operations

Specify:

11. M09 — Admin Interface
12. M12 — Operations & Deployment

These consume the already-established system contracts.

---

## 7. Implementation Waves

Implementation can follow the specification waves but may overlap where contracts are stable.

### Wave 1

- M01

### Wave 2

- M05
- M06

### Wave 3

- M02
- M04

### Wave 4

- M03
- M11

### Wave 5

- M10
- M07

### Wave 6

- M08
- M09

### Wave 7

- M12

### Wave 8

- End-to-end integration
- security review
- failure-path review
- deployment validation

---

## 8. Critical Handoffs

### M01 → Everyone

Must establish:

- configuration contract
- application bootstrap
- dependency wiring
- environment access conventions
- shared error/request primitives

### M05 → M04 / M03 / M07 / M09

Must establish:

- project persistence contract
- API-key persistence contract
- file metadata persistence contract
- transaction behavior

### M06 → M07 / M08 / M12

Must establish:

- storage interface
- storage key semantics
- physical storage behavior
- existence checks
- move-to-trash semantics

### M04 → M03 / M07 / M10 / M09

Must establish:

- project identity
- project status
- quota configuration
- file-size limits
- project settings

### M03 → M07 / M08 / M10 / M02

Must establish:

- authenticated project context
- authorization checks
- permission model

### M07 → M08 / M09 / M11

Must establish:

- file lifecycle semantics
- file state model
- replacement behavior
- deletion behavior
- metadata semantics

---

## 9. Cross-Module Invariants

These are architectural invariants rather than implementation preferences.

### Invariant 1 — Project isolation

A project must never be able to access another project's files or metadata through a valid credential.

### Invariant 2 — API keys are secrets

API keys must never be exposed through browser-facing application code or normal logs.

### Invariant 3 — Storage abstraction

Business/application modules must not construct physical filesystem paths themselves.

### Invariant 4 — Successful storage precedes successful file metadata

A file cannot be reported as successfully uploaded before its physical storage operation succeeds.

### Invariant 5 — Public delivery cannot expose internal paths

Public URLs must resolve through a controlled delivery mechanism.

### Invariant 6 — Deleted files are not active files

Deletion must update both the logical lifecycle and physical storage state according to the defined deletion contract.

### Invariant 7 — Administrative operations are protected

Project creation, disabling, API-key management, and similar control-plane operations require administrator authorization.

### Invariant 8 — Production errors are safe

Internal implementation details must never be returned as normal API error responses.

---

## 10. Module Specification Template

Every module specification should use the following structure:

```text
# Module: MXX — Name

## Status
PROPOSED / READY / IMPLEMENTING / REVIEW / ACCEPTED

## Owner
Module Owner role

## Purpose

## Responsibilities

## Non-Responsibilities

## Public Contracts

## Inputs

## Outputs

## Owned Data

## Dependencies

## Dependency Direction

## Invariants

## State Model

## Security Requirements

## Error / Failure Cases

## Edge Cases

## Configuration

## Integration Requirements

## Acceptance Criteria

## Open Questions

## Architectural Decisions

## Implementation Notes
```

Implementation notes must not silently introduce architectural decisions.

---

## 11. Readiness Gate

A module is **READY FOR IMPLEMENTATION** only when:

- responsibility is unambiguous
- non-responsibilities are defined
- dependencies are known
- contracts are explicit
- data ownership is explicit
- security requirements are explicit
- failure cases are covered
- acceptance criteria are testable
- open questions do not block implementation
- architectural conflicts are resolved

If these conditions are not met, the module remains in specification.

---

## 12. Recommended Next Step

The next module to formally specify is:

> **M01 — Core Foundation**

Reason:

M01 establishes the conventions that every later module depends on. It should therefore be finalized before database, storage, API, or authentication implementation begins.

After M01 is accepted, proceed to:

> **M05 — Data & Persistence**  
> **M06 — Storage Backend**

These two can then establish the infrastructure contracts required by the application modules.

---

## 13. Current State

### Architecture

**Established**

### Module map

**Established**

### Ownership roles

**Established**

### Module specifications

**Not yet written**

### Implementation

**Not yet started**

### Next artifact

**M01 — Core Foundation Module Specification**
