# FileVault M06 — Storage Backend

**Status:** READY FOR IMPLEMENTATION  
**Module:** M06 Storage Backend  
**Depends on:** M01 Core Foundation  
**Implementation:** Local filesystem  
**Future-compatible:** Object-storage backend may be added later without changing higher-level storage semantics.

---

## 1. Purpose

M06 provides FileVault's physical storage abstraction.

It owns the creation, location, reading, movement, restoration, and permanent removal of physical file objects.

Higher modules must not directly construct or manipulate filesystem paths for FileVault-managed files.

M06 v1 uses the local filesystem.

---

## 2. Responsibility

M06 owns:

- storage backend abstraction
- local filesystem implementation
- storage-key generation and validation
- project-scoped physical isolation
- filesystem path construction
- temporary upload files
- streamed writes
- atomic publication of new objects
- readable access to stored objects
- active → trash movement
- trash → active restoration
- permanent physical purge
- physical object existence/type checks
- physical byte-count reporting
- storage-specific exceptions
- filesystem security checks

M06 does not own:

- authentication
- authorization
- API keys
- project lifecycle
- project status
- quotas
- upload policy
- MIME validation policy
- original filename policy
- database persistence
- file lifecycle state
- public URLs
- HTTP behavior
- automatic retention policy
- cleanup scheduling
- reconciliation policy

---

## 3. Dependency boundary

M06 depends only on M01.

M06 may receive a project ID as a physical storage namespace, but must not depend on M04 services or query project-management state.

M06 must not determine whether a project exists, is active, disabled, over quota, or otherwise permitted to store files.

Those decisions belong to higher modules.

---

## 4. Storage abstraction

M06 exposes a backend-neutral storage contract.

Required operations:

```text
store(projectId, sourceStream)
    → StoredObject

openRead(projectId, storageKey)
    → readable stream

moveToTrash(projectId, storageKey)
    → void

restore(projectId, storageKey)
    → void

purge(projectId, storageKey)
    → void
```

The interface must not expose raw filesystem paths.

The interface must not expose local-filesystem implementation details to higher modules.

Native PHP streams/resources are sufficient for v1; no additional stream dependency is required.

---

## 5. StoredObject

A successful `store()` operation returns:

```text
storageKey
sizeBytes
```

No original filename, MIME type, public URL, physical path, checksum, or database ID is part of the storage result.

---

## 6. Storage key

M06 owns storage-key semantics.

The v1 format is:

```text
YYYY/MM/<64 lowercase hexadecimal characters>
```

Example:

```text
2026/10/9f4c2e8a...
```

The 64-character token is generated using 32 cryptographically secure random bytes.

Storage keys are:

- opaque to higher modules
- immutable
- backend-neutral
- independent of original filename
- independent of MIME type
- independent of file extension
- not database IDs
- not physical filesystem paths

M05 persists the key but does not construct or interpret it.

---

## 7. Physical directory layout

The configured storage root contains:

```text
<root>/
    projects/
        <project-id>/
            active/
                YYYY/
                    MM/
                        <token>
            trash/
                YYYY/
                    MM/
                        <token>
    .tmp/
```

Example:

```text
storage/
├── projects/
│   └── 42/
│       ├── active/
│       │   └── 2026/
│       │       └── 10/
│       │           └── 9f4c...
│       └── trash/
│           └── 2026/
│               └── 10/
│                   └── 9f4c...
└── .tmp/
```

`active` and `trash` are physical locations, not database state.

The year/month partition is organizational and must not be treated as application lifecycle state.

---

## 8. Original filenames

`original_name` is metadata only.

M06 must never use `original_name` when constructing a physical storage filename or path.

No user-supplied filename may influence physical object naming.

---

## 9. Project isolation

Every physical object is stored beneath its project-specific directory.

M06 receives the project ID as the storage namespace.

A storage operation must never resolve an object outside the requested project's storage subtree.

M06 does not verify project existence or lifecycle state.

---

## 10. Temporary storage

New objects are initially written to:

```text
<root>/.tmp/
```

Temporary filenames are independently generated and must not be based on original filenames.

Temporary files must not be considered stored objects.

---

## 11. Atomic publication

M06 must never stream directly into the final object path.

Required sequence:

```text
open temporary file
→ stream source into temporary file
→ verify successful completion
→ close/synchronize as appropriate
→ atomically rename temporary file to final active path
```

The temporary file and final destination must reside on the same filesystem so the final rename can be atomic.

The final destination must not be overwritten.

If the destination unexpectedly exists, M06 reports a conflict/failure.

---

## 12. Partial-write cleanup

If writing fails before publication:

- the incomplete temporary file must be removed when possible
- the final object must not exist
- the storage operation must fail

Cleanup failure must not hide the original storage failure.

---

## 13. File size

M06 counts the actual number of bytes successfully written.

The resulting byte count is returned through `StoredObject`.

M06 does not own the application's maximum upload size.

Maximum file-size policy belongs to higher-level validation/project policy.

M06 must stream data and must not require the entire file to reside in PHP memory.

---

## 14. Checksums

M06 v1 does not calculate or persist hashes/checksums.

No checksum dependency or algorithm is required.

Integrity/reconciliation hashing may be introduced later as a separate requirement.

---

## 15. Reading

`openRead()` returns a readable stream for the physical object.

M06 must verify that the expected object is a regular file.

It must reject symlinks and unexpected filesystem object types.

M06 must not return a filesystem path to the caller.

---

## 16. Trash

Moving an object to trash changes only its physical location:

```text
active/.../key
    →
trash/.../key
```

The `storage_key` remains unchanged.

M06 does not modify database status.

M07 coordinates the physical operation with M05 metadata changes.

---

## 17. Restore

Restore moves:

```text
trash/.../key
    →
active/.../key
```

The storage key remains unchanged.

M06 must not overwrite an existing destination.

If the source is missing, M06 reports `StorageObjectNotFoundException`.

If the destination already exists, M06 reports `StorageConflictException`.

---

## 18. Purge

Purge permanently removes the physical object from trash.

M06 uses filesystem deletion for the physical object.

M06 must not delete database records.

M06 must not decide when an object is eligible for purge.

If the object is missing, the operation reports that condition rather than falsely claiming a successful physical deletion.

---

## 19. Missing physical objects

M06 must report missing physical objects explicitly.

It must never:

- silently delete database metadata
- recreate missing objects
- silently ignore the discrepancy
- fabricate successful retrieval

Higher-level modules determine the resulting application behavior.

---

## 20. Path safety

M06 must construct all filesystem paths internally.

It must never accept arbitrary caller-provided filesystem paths for managed objects.

Storage-key validation must reject:

- traversal components
- unexpected separators
- absolute paths
- invalid year/month values
- invalid characters
- malformed tokens

Project IDs must be valid positive integers.

---

## 21. Symlink handling

Managed storage roots/directories must not be treated as trusted symlink targets.

M06 must reject managed objects that are symlinks.

The configured storage root must resolve to a controlled filesystem location.

The design does not attempt to provide impossible guarantees against an attacker with unrestricted filesystem-level access to the hosting account.

---

## 22. Storage root

The local backend receives one configured absolute storage root through M01 configuration.

No insecure fallback path is permitted.

The storage root should be outside the web-accessible document root.

M06 must fail configuration/startup validation if the configured root cannot be safely used.

---

## 23. Permissions

Default local-filesystem permissions:

```text
directories: 0700
files:       0600
```

Deployment-specific permission requirements may be accommodated where necessary, but M06 must not default to world-readable storage.

Physical storage must not be directly public.

---

## 24. Directory creation

Directories are created lazily when required.

M06 does not pre-create project/year/month directories unnecessarily.

Directory creation must be recursive and must safely tolerate an already-existing directory.

Failure to create required directories is a storage failure.

---

## 25. Replacement

M06 does not expose a business-level `replace()` operation.

Replacement is orchestrated by M07.

A replacement must result in a new physical storage identity.

Existing physical objects must never be overwritten in place.

The old object may subsequently be moved to trash according to M07's lifecycle sequence.

---

## 26. Cross-system failure boundary

Filesystem operations and MySQL transactions are separate systems.

M06 does not provide filesystem transactions.

M05's database transaction wrapper cannot make filesystem operations atomic.

M07 owns sequencing and compensation when a workflow spans M06 and M05.

Possible orphaned physical objects after DB failure are an acknowledged consistency condition and must not be hidden by M06.

---

## 27. Exception hierarchy

M06 provides a small storage-specific exception hierarchy:

```text
StorageException
├── StorageConfigurationException
├── StorageObjectNotFoundException
├── StorageConflictException
└── StorageOperationException
```

Exceptions should preserve the underlying cause where appropriate.

M06 must not emit HTTP responses or application-level API error payloads.

---

## 28. Security boundary

M06 is responsible for:

- preventing arbitrary filesystem path injection
- preventing traversal through storage keys
- preventing physical filename collisions
- avoiding original-name-based paths
- preventing direct overwrite of existing objects
- isolating project storage subtrees
- avoiding symlink-based managed-object access
- keeping physical storage outside the public document root

M06 is not responsible for:

- API authentication
- API authorization
- upload MIME policy
- project authorization
- abuse/rate limiting
- public/private visibility policy

---

## 29. Testing requirements

Tests must use isolated temporary storage roots.

Required coverage includes:

### Storage-key tests

- valid generation
- correct format
- uniqueness
- malformed key rejection

### Path tests

- project isolation
- active path
- trash path
- year/month partitioning
- traversal rejection

### Store tests

- successful stream storage
- accurate byte count
- original filename independence
- temporary-file cleanup
- atomic publication
- no overwrite

### Failure tests

- source-read failure
- partial write
- directory creation failure
- publication failure
- cleanup behavior

### Read tests

- correct contents
- missing object
- non-regular object
- symlink rejection

### Trash/restore tests

- active → trash
- trash → active
- missing source
- destination conflict
- unchanged storage key

### Purge tests

- successful physical deletion
- missing object reporting
- refusal to delete unexpected filesystem objects

### Isolation tests

A storage object belonging to one project must never resolve through another project's namespace.

---

## 30. Explicit non-goals

M06 v1 does not implement:

- checksums
- deduplication
- file versioning
- object-storage providers
- multipart uploads
- resumable uploads
- background workers
- automatic trash expiration
- filesystem reconciliation
- backup management
- compression
- encryption-at-rest
- arbitrary filesystem path access
- direct file URLs
- CDN integration
- database access
- quota management
- project lifecycle management

These can be introduced later only as explicit requirements.

---

## 31. Acceptance criteria

M06 is accepted when:

1. Higher modules can store a stream without knowing a filesystem path.
2. Every stored object receives a unique opaque storage key.
3. Original filenames never influence physical storage paths.
4. Objects are physically isolated by project.
5. New objects are atomically published from temporary storage.
6. Partial writes do not produce successful final objects.
7. Existing objects are never overwritten.
8. Active objects can be moved to trash without changing their storage key.
9. Trashed objects can be restored.
10. Trashed objects can be physically purged.
11. Missing physical objects are explicitly reported.
12. No filesystem path escapes the configured storage root.
13. Symlinked managed objects are rejected.
14. Storage operations use streams rather than loading whole files into memory.
15. Actual stored byte count is returned.
16. M06 contains no database or lifecycle-policy logic.
17. M06 has no dependency on M04.
18. The implementation remains compatible with Bluehost/shared-hosting constraints.
19. Tests exercise real temporary filesystem behavior.
20. The local filesystem implementation is behind the storage abstraction.