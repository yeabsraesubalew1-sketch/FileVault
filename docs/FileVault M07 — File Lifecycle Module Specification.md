# FileVault M07 — File Lifecycle

**Status:** READY FOR IMPLEMENTATION  
**Module:** M07 File Lifecycle  
**Depends on:** M01, M03, M04, M05, M06  
**HTTP dependency:** None  
**Purpose:** Coordinate logical file lifecycle operations across metadata persistence and physical storage.

---

## 1. Purpose

M07 owns FileVault's file lifecycle orchestration.

It coordinates:

- file creation/upload
- lifecycle validation
- replacement
- logical trash
- restore
- permanent purge
- lifecycle transition enforcement
- cross-system sequencing
- compensation when database and filesystem operations cannot be atomic

M07 does not own database persistence implementation or physical filesystem implementation.

---

## 2. Ownership

M07 owns:

- file lifecycle policy
- valid lifecycle transitions
- lifecycle operation sequencing
- orchestration between M05 and M06
- compensation after cross-system failure
- replacement semantics
- lifecycle-level interpretation of lower-level failures
- ensuring invalid lifecycle operations are rejected
- ensuring metadata and physical storage are coordinated as far as v1 permits

M07 does not own:

- HTTP routing or response formatting
- API authentication
- project authorization
- project lifecycle
- project quotas or project limits
- database connection management
- SQL queries
- schema/migrations
- filesystem paths
- filesystem permissions
- physical storage implementation
- public file delivery
- admin UI
- rate limiting
- abuse controls
- logging infrastructure
- background reconciliation
- automatic trash expiration

---

## 3. Dependency Direction

M07 may consume:

- authenticated/authorized context supplied by M03
- project policy supplied by M04
- file metadata repositories supplied by M05
- storage operations supplied by M06

M07 must not depend on HTTP-specific classes or API response behavior.

M02 invokes the lifecycle/application service.

---

## 4. Lifecycle States

Only two persisted lifecycle states exist:

```text
active
trashed
```

There are no persisted transitional states.

`purged` / `deleted` is not a state. A successfully purged file has no database record.

---

## 5. Valid Transitions

### Normal lifecycle

```text
active
  │
  └── trash → trashed
                │
                └── restore → active
```

### Permanent deletion

```text
trashed
  │
  └── purge → no file record
```

### Replacement

Replacement does not mutate the identity of the existing file.

It creates a new file record and physical object.

Conceptually:

```text
old active file
    ↓
old trashed file

new file
    ↓
new active file
```

No file-versioning model is introduced.

---

## 6. Upload/Create

Required logical sequence:

```text
validate request
→ verify project policy
→ M06 store()
→ M05 create()
→ success
```

M05 metadata must not be created before successful physical storage.

The resulting metadata uses the `storageKey` and `sizeBytes` returned by M06.

M07 must not construct or modify the storage key.

---

## 7. Upload Failure Handling

### Storage failure

If M06 `store()` fails:

- no file metadata is created
- the operation fails
- M07 must not report successful creation

### Database failure after successful storage

If M06 succeeds but M05 creation fails:

M07 must attempt compensation:

```text
moveToTrash(newObject)
→ purge(newObject)
```

The upload operation remains failed.

If compensation succeeds, no persistent file should remain.

If compensation fails, M07 must not report success and must surface a lifecycle consistency failure while preserving the underlying failure information.

M07 must not pretend that the database transaction made the physical operation atomic.

---

## 8. Trash

Trash is a logical lifecycle operation coordinated with physical movement.

Required sequence:

```text
verify file exists and is ACTIVE
→ M06 moveToTrash()
→ M05 markTrashed()
→ success
```

### Storage failure

If physical movement fails:

- metadata remains `active`
- operation fails

### Database failure

If physical movement succeeds but `markTrashed()` fails:

```text
M06 restore()
```

must be attempted.

If compensation succeeds:

```text
physical = active
metadata = active
```

If compensation fails, M07 must report a lifecycle consistency failure.

M07 must not mark metadata as trashed merely because physical movement succeeded.

---

## 9. Restore

Required sequence:

```text
verify file exists and is TRASHED
→ M06 restore()
→ M05 restore()
→ success
```

### Storage failure

If physical restoration fails:

- metadata remains `trashed`
- operation fails

### Database failure

If physical restoration succeeds but M05 restore fails:

```text
M06 moveToTrash()
```

must be attempted.

If compensation succeeds:

```text
physical = trash
metadata = trashed
```

If compensation fails, M07 must report a lifecycle consistency failure.

---

## 10. Purge

Purge is only valid for a `trashed` file.

Required sequence:

```text
verify metadata = TRASHED
→ M06 purge()
→ M05 purge()
→ success
```

Physical deletion occurs before metadata deletion.

This prevents a database record from disappearing while its physical object remains undeleted.

### Physical purge failure

If M06 fails for a reason other than physical absence:

- metadata remains `trashed`
- operation fails
- M05 purge is not attempted

### Missing physical object

M06 explicitly reports missing physical objects.

For an explicit purge of a metadata record already known to be `trashed`, M07 may interpret physical absence as an already-satisfied physical purge condition.

It must then continue to:

```text
M05 purge()
```

This behavior is specific to the purge operation.

M07 must not globally treat missing physical objects as success.

### Database failure after physical purge

If M06 successfully removes the physical object but M05 purge fails:

```text
physical = gone
metadata = trashed
```

M07 must report failure.

The trashed database record remains available for retry.

A subsequent purge may encounter a missing physical object and use the idempotent purge behavior defined above.

M07 must not recreate a physical object.

---

## 11. Replacement

Replacement creates a new file identity.

Required sequence:

```text
validate replacement
→ M06 store(new)
→ M06 moveToTrash(old)
→ M05 transaction:
      create(new metadata)
      markTrashed(old metadata)
→ success
```

The old and new metadata operations must be committed atomically within the M05 database transaction.

The old physical object must never be overwritten.

The new physical object must have a new storage identity.

---

## 12. Replacement Failure Handling

### New storage fails

If M06 cannot store the replacement:

- old file remains unchanged
- no new metadata is created
- operation fails

### Old physical trash operation fails

If the new object is stored successfully but the old object cannot be moved to trash:

- old file remains active
- new physical object must be compensated

Compensation:

```text
moveToTrash(new)
→ purge(new)
```

If compensation fails, report lifecycle consistency failure.

### Database transaction fails

If:

```text
new physical = active
old physical = trash
```

but the metadata transaction fails:

M07 must attempt:

```text
restore(old)
moveToTrash(new)
purge(new)
```

The desired compensated state is the original one:

```text
old physical = active
old metadata = active

new physical = gone
new metadata = nonexistent
```

If compensation cannot fully restore that state, report lifecycle consistency failure.

---

## 13. Database Transactions

M07 may use the M05 transaction wrapper for DB-only atomic groups.

The primary v1 use is replacement metadata:

```text
create new file record
+
mark old file trashed
```

These operations must commit or roll back together.

M07 must never assume that this transaction includes filesystem operations.

M07 must not introduce:

- distributed transactions
- nested transactions
- savepoints
- transaction coordinators
- filesystem transaction abstractions

---

## 14. Lifecycle Guards

M07 must reject invalid transitions before attempting physical operations.

Examples:

```text
trash(active)      → valid
trash(trashed)     → invalid

restore(trashed)   → valid
restore(active)    → invalid

purge(trashed)     → valid
purge(active)      → invalid
```

Project-scoped file lookup must be used for project-owned lifecycle operations.

M07 must not allow one project to operate on another project's file.

---

## 15. Missing Metadata

If the requested file metadata does not exist for the project:

- the lifecycle operation fails
- no storage operation is attempted

M07 must not attempt to discover physical objects independently of M05 metadata.

---

## 16. Missing Physical Objects

M06 is responsible for reporting missing physical objects.

M07 must not:

- recreate missing objects
- silently delete metadata
- fabricate successful retrieval
- treat missing objects as universally harmless

Only the explicitly defined purge-idempotency behavior may interpret physical absence as an already-satisfied physical deletion.

Other lifecycle operations must treat unexpected physical absence as a failure/consistency problem.

---

## 17. Validation Boundary

M07 orchestrates lifecycle validation but does not own project policy.

Validation responsibilities are divided as follows:

### M03

- authentication
- authorization
- project access

### M04

- project lifecycle/status
- quotas
- project-level file limits
- project policy

### M07

- lifecycle operation validity
- lifecycle state transitions
- file-operation orchestration
- lifecycle-specific metadata validation

### M06

- physical storage safety
- storage-key validity
- filesystem constraints
- actual stored byte count

M07 must not duplicate M04 quota/limit rules or M06 filesystem rules.

---

## 18. Metadata Creation

For successful creation, M07 supplies M05 with:

```text
project_id
original_name
storage_key
mime_type
size_bytes
visibility
status
created_at
updated_at
trashed_at
```

The physical storage result is authoritative for:

```text
storage_key
size_bytes
```

The caller must not be able to choose the physical storage key.

New files begin in:

```text
active
```

with:

```text
trashed_at = NULL
```

---

## 19. Trash Metadata

When a file is successfully trashed:

```text
status = trashed
trashed_at = current UTC timestamp
updated_at = current UTC timestamp
```

The `storage_key` does not change.

The physical object's movement from active to trash is handled exclusively by M06.

---

## 20. Restore Metadata

When a file is successfully restored:

```text
status = active
trashed_at = NULL
updated_at = current UTC timestamp
```

The `storage_key` remains unchanged.

---

## 21. Purge Metadata

After successful physical purge, M07 permanently removes the file metadata through:

```text
M05 purge()
```

No generic database delete operation is introduced.

---

## 22. Timestamp Policy

M07 supplies application-generated UTC timestamps using the project's established timestamp conventions.

Lifecycle timestamps must not depend on database-local timezone behavior.

---

## 23. Compensation Policy

Compensation is:

- explicit
- best-effort
- limited to the inverse operations already exposed by M06
- never presented as transactional rollback
- never allowed to hide the original failure

M07 must distinguish between:

```text
operation failure
```

and:

```text
operation failure + compensation failure
```

The latter represents a cross-system consistency problem.

M07 must not introduce a background reconciliation system.

Filesystem reconciliation is outside v1 scope.

---

## 24. Idempotency

M07 should make lifecycle operations deterministic with respect to current metadata state.

Examples:

```text
trash(active)       → perform trash
trash(trashed)      → reject invalid transition

restore(trashed)    → perform restore
restore(active)     → reject invalid transition

purge(trashed)      → perform purge
purge(trashed,
      physical missing)
                    → complete metadata purge
```

No general-purpose idempotency-key framework is required for M07 v1.

---

## 25. Public Module Interface

M07 should expose semantic lifecycle operations rather than generic CRUD.

Conceptually:

```text
create(...)
replace(...)
trash(projectId, fileId)
restore(projectId, fileId)
purge(projectId, fileId)
```

The exact PHP interface/class structure may be finalized during implementation without changing these semantics.

M07 must not expose:

```text
delete()
setStatus()
updateFile()
moveFile()
filesystemPath()
```

as generic escape hatches.

---

## 26. Exception Boundary

M07 may define lifecycle-specific exceptions sufficient to distinguish:

- invalid lifecycle operation
- lifecycle operation failure
- cross-system consistency/compensation failure

M07 must preserve underlying M05/M06 exceptions where diagnostically useful.

M07 must not emit HTTP responses.

M07 must not define the final API error envelope; that belongs to the HTTP/error modules.

---

## 27. Testing Requirements

M07 testing must cover both successful lifecycle operations and cross-system failure paths.

### Create

- successful create
- storage failure
- database failure after storage success
- successful compensation
- failed compensation

### Trash

- active → trashed
- invalid trashed → trash
- storage failure
- database failure
- successful restore compensation
- failed compensation

### Restore

- trashed → active
- invalid active → restore
- storage failure
- database failure
- successful trash compensation
- failed compensation

### Purge

- trashed → gone
- invalid active → purge
- physical purge failure
- physical object missing
- database failure after physical purge
- retry after database failure
- no physical recreation

### Replacement

- successful replacement
- new storage failure
- old trash failure
- metadata transaction failure
- complete compensation
- compensation failure
- old/new project isolation
- new storage identity
- old metadata becomes trashed
- no in-place overwrite

### State guards

Every invalid lifecycle transition must be rejected without performing the corresponding physical operation.

### Project isolation

A lifecycle operation must never manipulate a file belonging to another project.

---

## 28. Explicit Non-Goals

M07 v1 does not implement:

- file version history
- file-version tables
- background jobs
- automatic trash expiration
- scheduled purge
- filesystem reconciliation
- orphan scanning
- checksums
- deduplication
- resumable uploads
- multipart uploads
- object-storage-specific behavior
- direct filesystem access
- public URLs
- API authentication
- HTTP controllers
- rate limiting
- quota implementation
- audit logging subsystem
- distributed transactions
- filesystem transactions

---

## 29. Acceptance Criteria

M07 is acceptable when:

1. File creation stores physical data before creating metadata.
2. Failed metadata creation does not report successful upload.
3. Successful physical creation has an explicit compensation path.
4. Only `active` and `trashed` are persisted lifecycle states.
5. Invalid lifecycle transitions are rejected.
6. Trash coordinates physical movement and metadata state.
7. Restore coordinates physical movement and metadata state.
8. Purge removes physical data before permanently removing metadata.
9. Purge can safely retry after database failure.
10. Missing physical objects are not globally ignored.
11. Replacement creates a new file identity.
12. Replacement never overwrites the existing physical object.
13. Replacement metadata operations are atomically committed within M05.
14. Replacement has explicit physical compensation.
15. M07 never constructs filesystem paths.
16. M07 never performs SQL directly.
17. M07 never implements filesystem operations directly.
18. M07 uses M05 and M06 through their established contracts.
19. Cross-system compensation failures are distinguishable from ordinary operation failures.
20. No filesystem/database atomicity is falsely claimed.
21. No pending/temporary lifecycle database state is introduced.
22. No background reconciliation system is introduced.
23. Tests cover both successful and failure/compensation paths.
24. The module remains compatible with Bluehost/shared-hosting constraints.