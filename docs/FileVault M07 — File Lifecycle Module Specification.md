# FileVault M07 — File Lifecycle

**Status:** PROPOSED — Architecture & Module Specification (Review Revision 7: Physical-I/O Fencing, In-Transaction Execution & Recovery Protocol)  
**Module:** M07 — File Lifecycle  
**Owner:** File Owner  
**Architectural Authority:** FileVault Architect  
**Depends on:** M01 Core Foundation, M03 Authentication & Authorization, M04 Project Management, M05 Data & Persistence, M06 Storage Backend  
**HTTP dependency:** None  
**Purpose:** Coordinate logical file lifecycle operations across project policy/quota accounting, metadata persistence, and physical storage with verifiable concurrency serialization, physical-I/O fencing, durable crash consistency, reconstructible operations, and deterministic recovery.

---

## 1. Purpose

M07 owns FileVault's file lifecycle orchestration.

It coordinates:

- file creation/upload
- lifecycle state validation
- replacement (dual-file coordination and quota transition)
- logical trash
- restore
- permanent purge
- lifecycle transition enforcement
- authoritative project quota enforcement and usage accounting
- cross-system sequencing between physical storage and database persistence
- same-file concurrency protection, mutual exclusion, and physical-I/O fencing
- durable operation journaling (`file_operations`), operation ownership, and request-triggered reconciliation
- compensation ordering when database and filesystem operations cannot be atomic
- reporting cross-system consistency anomalies and handling indeterminate transaction outcomes

M07 is an orchestrator. It does not own database persistence implementation, SQL generation, project quota policy definitions, or physical filesystem drivers.

---

## 2. Ownership & Boundaries

### 2.1 Owned Responsibilities

M07 owns:

- file lifecycle policy and valid state transitions
- lifecycle operation sequencing
- orchestration between M04 (project quota & policy), M05 (metadata persistence), and M06 (physical storage)
- authoritative quota check execution and `UsageDelta` coordination
- durable operation lifecycle logging (intent phase, storage mutation, completion phase)
- operation ownership verification and fencing against stale execution
- request-triggered inline reconciliation of interrupted operations after process crashes
- physical and metadata compensation sequencing after cross-system failures
- handling transaction outcome distinctions (confirmed rollback vs confirmed commit vs indeterminate commit)
- proof-of-ownership compensation rules (preventing stale compensation from undoing concurrent work)
- replacement semantics (new identity creation, temporary dual-file accounting)
- lifecycle-level interpretation of lower-level storage and database failures
- defining deterministic failure and divergence boundaries between metadata and storage

### 2.2 Boundary Exclusions

M07 does **not** own:

- HTTP routing, controllers, or response formatting (M02)
- API authentication or credential verification (M03)
- Project operational policy or quota limit rules (M04 owns `ProjectQuota`, `ProjectPolicy`, `ProjectStatus`, and `UsageDelta` semantics)
- Database connection management, PDO configuration, SQL queries, or schema migrations (M05)
- Filesystem paths, directory hashing, file permissions, or disk I/O (M06)
- Public file retrieval, HTTP streaming, caching headers, or public URLs (M08)
- Administrative sessions or web UI (M09)
- Rate limiting or abuse controls (M10)
- Logging infrastructure, sinks, or telemetry format (M11)
- Automatic background trash expiration, continuous daemons, or offline reconciliation workers (M12)

---

## 3. Verified Repository Baseline vs. Proposed Module Extensions

To maintain governance integrity, the exact existing verified codebase contracts are distinguished from the proposed module capabilities required for crash consistency.

### 3.1 Verified Existing Repository Baseline

- **M01 Core Foundation:** Base primitives and immutable configuration context.
- **M03 Authentication:** Authenticated project execution context.
- **M04 Project Management (Verified Accepted Baseline):**
  - `ProjectQuota`: Enforces limits via `fits(ProjectUsage, UsageDelta, int $fileSize): QuotaCheckResult`.
  - `ProjectPolicy`: Enforces operational status via `requireOperational(Project $project)` (evaluates `$project->isOperational()`).
  - `ProjectStatus`: Status enumeration (`ACTIVE`, `RESTRICTED`, `DISABLED`).
  - `UsageDelta`: Immutable delta factory methods (`newFile`, `trash`, `restore`, `replace`, `purge`).
  - `Project`: Aggregate root exposing `getQuota()`, `getUsage()`, `applyUsageDelta()`, `isOperational()`.
- **M05 Data & Persistence (Verified Accepted Baseline):**
  - `Database`: Transaction management via `transaction(callable $callback)`.
  - `ProjectRepository`: Locking and persistence via `findByIdForUpdate(int $id)` (`SELECT ... FOR UPDATE`) and `save(Project $project)`.
  - `FileRepository`: Persistence methods `create()`, `findById()`, `findByProjectAndId()`, `markTrashed()`, `restore()`, `purge()`.
  - `files` Schema (Migration `003_create_files.sql`): Columns `id`, `project_id`, `original_name`, `storage_key`, `mime_type`, `size_bytes`, `visibility`, `status`, `created_at`, `updated_at`, `trashed_at`.
  - *Identified Gaps:* The existing M05 contract lacks row-level locking on files (`findByIdForUpdate`), lacks durable operation journaling, and has no idempotency store.
- **M06 Storage Backend (Verified Accepted Baseline):**
  - `StorageBackend`: `store()`, `openRead()`, `moveToTrash()`, `restore()`, `purge()`.
  - `StoredObject`: Value object carrying `$storageKey` and `$sizeBytes`.
  - `LocalFilesystemStorage`: `purge()` deletes objects only from the `trash/` directory; `moveToTrash()` moves objects from `active/` to `trash/` via filesystem `link()`/`unlink()`.
  - *Identified Gaps:* The existing M06 contract lacks storage location inspection across directories (`locateObject`) and has no repair primitives for interrupted hardlink sequences.

```text
       M02 HTTP / API Layer
               │
               ▼
       M07 File Lifecycle Orchestration
       ┌───────┼───────┐
       ▼       ▼       ▼
      M04     M05     M06
    Project  Data &  Storage
    Policy/ Persist. Backend
    Quotas   (Ops Jnl) (Locate)
```

---

### 3.2 Proposed Module Extensions (Requiring Architectural Approval)

The following extensions are formally submitted as proposals owned by M05 and M06:

#### Proposed for M05 (Data & Persistence)
1. **Row-Level Locking on Files:**
   - Method: `FileRepository::findByIdForUpdate(int $projectId, int $fileId): ?FileRecord`
   - SQL: `SELECT * FROM files WHERE id = :id AND project_id = :project_id FOR UPDATE;`
2. **Durable Lifecycle Operation Journal (Migration 004):**
   - Migration `004_create_file_operations.sql`:
     ```sql
     CREATE TABLE file_operations (
         id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
         operation_uuid     VARCHAR(36) NOT NULL,
         project_id         BIGINT UNSIGNED NOT NULL,
         file_id            BIGINT UNSIGNED NOT NULL,
         operation_type     VARCHAR(20) NOT NULL, -- 'replace', 'trash', 'restore', 'purge'
         state              VARCHAR(20) NOT NULL, -- 'pending'
         storage_key        VARCHAR(512) NOT NULL,
         target_storage_key VARCHAR(512) NULL,    -- replacement newStorageKey
         new_original_name  VARCHAR(255) NULL,    -- replacement reconstruction
         new_mime_type      VARCHAR(127) NULL,    -- replacement reconstruction
         new_size_bytes     BIGINT UNSIGNED NULL, -- replacement reconstruction
         new_visibility     VARCHAR(16) NULL,     -- replacement reconstruction
         created_at         DATETIME(6) NOT NULL,
         updated_at         DATETIME(6) NOT NULL,
         PRIMARY KEY (id),
         UNIQUE KEY uq_file_operations_uuid (operation_uuid),
         UNIQUE KEY uq_file_operations_file (project_id, file_id),
         CONSTRAINT fk_file_operations_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
         CONSTRAINT fk_file_operations_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
     ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     ```
   - Repository: `FileOperationRepository`:
     - `createIntent(...)`: Inserts pending journal row.
     - `findActive(int $projectId, int $fileId): ?FileOperationRecord`: Retrieves active pending journal row.
     - `delete(int $operationId): void`: Clears completed journal row.

#### Proposed for M06 (Storage Backend)
1. **Physical Storage Inspection:**
   - Method: `StorageBackend::locateObject(int $projectId, string $storageKey): ObjectLocation`
   - Enum `ObjectLocation`:
     - `ACTIVE`: Exists only in `active/`.
     - `TRASH`: Exists only in `trash/`.
     - `BOTH`: Exists in both `active/` and `trash/` (interrupted link/unlink).
     - `NONE`: Absent from both directories.
2. **Idempotent Repair Primitives:**
   - `StorageBackend::ensureTrashClean(int $projectId, string $storageKey): void` (unlinks redundant `active/` entry if in `BOTH`).
   - `StorageBackend::ensureActiveClean(int $projectId, string $storageKey): void` (unlinks redundant `trash/` entry if in `BOTH`).

---

## 4. Elimination of Timeout-Based Preemption & Physical Fencing Hazard

Revision 6 proposed allowing concurrent requests to reconcile operations older than 30 seconds. That mechanism is fundamentally unsafe and is formally withdrawn.

### 4.1 The Stale-Actor Preemption Race

In shared-hosting environments without kernel-level distributed fencing tokens:
1. **Request A** commits its intent in Tx 1, enters Tx 2, and prepares to execute physical I/O.
2. **Request A Stalls:** Request A experiences a severe delay (e.g. swap thrashing, cloud burst CPU starvation, thread preemption, or network filesystem latency) exceeding 30 seconds. Request A's PHP process is still alive.
3. **Request B Preempts:** Request B arrives, observes that Request A's intent is older than 30 seconds, assumes Request A is dead, and begins reconciling the intent.
4. **Concurrent Physical Mutation:** Request A resumes execution. Request A and Request B **concurrently execute conflicting filesystem mutations (`link`, `unlink`) on the exact same directory paths**.
5. **Race Outcome:** Filesystem corruption, partial moves, or unhandled exceptions occur. Both processes attempt to finalize or recover the operation.

### 4.2 Why Database Tokens Cannot Fence Local POSIX Filesystem Calls
A database column (`operation_uuid`, lease token, timestamp) exists entirely within MySQL. The local POSIX filesystem kernel (`ext4`, `xfs`, `NTFS`) has zero knowledge of database rows. The filesystem executes system calls (`link()`, `unlink()`, `rename()`) unconditionally. Therefore:

- A database check executed *after* physical I/O cannot undo physical mutations that already occurred.
- A database check executed *before* physical I/O is subject to TOCTOU stalls if the lock is released.
- **Rule:** Preemption based on elapsed time or timestamps is prohibited. Mutual exclusion during physical I/O must be enforced by an active lock that terminates if and only if the process terminates.

---

## 5. The Authoritative Durable-Intent Protocol with In-Transaction Physical I/O

To guarantee physical fencing, race-free concurrency, and deterministic crash recovery, FileVault v1 adopts a **two-transaction protocol that holds exclusive row locks during physical storage mutations**.

```text
┌────────────────────────────────────────────────────────┐
│ Transaction 1: Durable Intent Logging                  │
│ 1. Acquire Project lock (findByIdForUpdate)            │
│ 2. Acquire File lock (findByIdForUpdate)               │
│ 3. Run Inline Reconciliation if pending op exists      │
│ 4. Validate metadata, preconditions & verify quota     │
│ 5. Insert file_operations (state = 'pending', UUID)    │
│ 6. Commit Transaction 1                                │
└────────────────────────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ Transaction 2: Execution and Finalization              │
│ 1. Reacquire Project lock (findByIdForUpdate)          │
│ 2. Reacquire File lock (findByIdForUpdate)             │
│ 3. Verify exact intent identity (UUID) in journal      │
│ 4. EXECUTE PHYSICAL STORAGE MUTATIONS UNDER ROW LOCKS  │
│ 5. Apply metadata updates and Project UsageDelta       │
│ 6. Delete file_operations record atomically            │
│ 7. Commit Transaction 2                                │
└────────────────────────────────────────────────────────┘
```

---

### 5.1 Step-by-Step Protocol Specification

#### Transaction 1 — Durable Intent
1. **Lock Acquisition:** Acquire Project lock (`findByIdForUpdate`), then File lock (`findByIdForUpdate`) in mandatory order.
2. **Reconciliation Check:** Check `file_operations` for `(project_id, file_id)`. If an active record exists, execute inline reconciliation (Section 6) before proceeding.
3. **Precondition & Quota Validation:** Validate file status, operational policy, and quota limits under the lock.
4. **Persist Intent:** Insert the complete, reconstructible operation record into `file_operations` with a fresh `operation_uuid` and `state = 'pending'`.
5. **Commit Tx 1:** Commit the transaction. The intent is now durably persisted on disk in MySQL.

#### Transaction 2 — Execution & Finalization
1. **Reacquire Locks:** Reacquire Project lock (`findByIdForUpdate`), then File lock (`findByIdForUpdate`) in mandatory order.
2. **Intent Verification:** Query `file_operations` for `(project_id, file_id)` where `operation_uuid = :uuid`. If missing or modified, abort immediately (fencing protection).
3. **Physical Storage Mutation Under Lock:**
   - Execute the local filesystem operations (`link()`, `unlink()`, `purge()`) **while holding the row locks**.
   - Because the row locks are actively held in InnoDB, **no competing request can acquire locks or execute operations on this project or file**.
4. **Metadata & Quota Finalization:**
   - In the same transaction, apply metadata changes to `files` (`status`, `trashed_at`, `updated_at`, or `DELETE`).
   - Apply project `UsageDelta` via `$project->applyUsageDelta(...)` and persist via `ProjectRepository::save($project)`.
   - Delete the `file_operations` record.
5. **Commit Tx 2:** Commit the transaction. Locks are released; operation is fully finalized.

---

### 5.2 Concurrency, Stale-Actor Fencing & Crash Analysis

#### Case 1: Competing Request Arrives While Process A is Live in Tx 2
- Process A is executing physical I/O in Tx 2. Process A holds the exclusive row locks on `projects` and `files`.
- Process B arrives and calls `findByIdForUpdate` on the Project and File.
- **Process B BLOCKS in MySQL waiting for the row lock.**
- Process B cannot read, cannot reconcile, and cannot touch physical storage while Process A is alive.
- When Process A commits Tx 2, Process A releases the locks. Process B unblocks, reads the row, finds the operation completed and intent deleted, and proceeds normally.

#### Case 2: Process A Crashes During Physical I/O in Tx 2
- Process A terminates abruptly (`kill -9`, OOM, power cut, segfault) while executing physical mutations in Tx 2.
- **Database Behavior:** The operating system closes Process A's TCP connection to MySQL. MySQL detects client disconnection, automatically rolls back Tx 2, and **releases the row locks**.
- **Metadata State:** Unchanged (reverted by InnoDB rollback).
- **Physical State:** May be in original state, intermediate state (`BOTH`), or target state.
- **Journal State:** The `file_operations` record **survives intact in MySQL** because it was committed in Tx 1!
- **Recovery:** Process B unblocks and acquires the row locks.
  - Process B is **guaranteed that Process A is dead**, because the row locks could only be acquired if Process A disconnected.
  - Process B inspects `file_operations`, sees the pending intent, inspects physical storage via `locateObject`, and completes inline reconciliation deterministically without any possibility of Request A interfering.

#### Case 3: Process A Stalls and Database Lock Wait Times Out
- If Process A holds Tx 2 longer than `innodb_lock_wait_timeout` (e.g. 5 seconds), Process B's lock request throws a `PDOException` (lock wait timeout exceeded).
- Process B catches this exception and returns HTTP `409 Conflict` or `503 Service Unavailable` with `Retry-After: 1`.
- Process B does NOT preempt or corrupt state.

#### Case 4: Lock Duration & Shared-Hosting Constraints
- Streaming file uploads (`create`, `replace` new file) occur **strictly outside database transactions**. Multi-megabyte network transfers never hold locks.
- Physical operations inside Tx 2 are restricted exclusively to local directory metadata operations (`link()` and `unlink()` on already existing files).
- Local POSIX `link()`/`unlink()` modify directory entries without streaming data. Hold times are discrete, ensuring high throughput on single-node shared hosting.

---

## 6. Commit Uncertainty & Resumed Process Protocol

A database commit failure is not proof of rollback. Both transactions must handle ambiguous outcomes deterministically.

### 6.1 Transaction 1 (Intent) Commit Outcomes
- **Confirmed Commit:** Acknowledgement received. Proceed to Tx 2.
- **Confirmed Rollback:** Exception inside transaction or rollback confirmed. Abort. Re-throw exception.
- **Indeterminate Commit (`commit()` throws `\PDOException`):**
  - **Rule: DO NOT PERFORM PHYSICAL I/O.**
  - The process cannot know if the intent was persisted.
  - The process must re-connect to MySQL, acquire row locks, and query `file_operations` for its `operation_uuid`.
  - If found: Intent was committed. Proceed to Tx 2.
  - If missing: Intent rolled back. Abort operation; re-throw exception.

### 6.2 Transaction 2 (Execution & Finalization) Commit Outcomes
- **Confirmed Commit:** Acknowledgement received. Operation complete. Return success.
- **Confirmed Rollback (before commit):** Physical storage was modified, but DB rolled back. The intent in `file_operations` remains intact. The next request locking the row will reconcile.
- **Indeterminate Commit (`commit()` throws `\PDOException`):**
  - **Rule: DO NOT EXECUTE DESTRUCTIVE PHYSICAL COMPENSATION.**
  - The process must re-connect to MySQL, acquire row locks, and query `file_operations` for its `operation_uuid`.
  - If missing: Tx 2 committed successfully on the server before disconnect. Return success!
  - If present: Tx 2 rolled back on the server. Inspect physical storage via `locateObject` and complete finalization.

---

## 7. Mandatory Lock Order & Deadlock Elimination

Reconciling M04 project quota locking with M07 file lifecycle locking requires a single, mandatory global acquisition order.

```text
===================================================================
MANDATORY GLOBAL LOCK ORDER:
1. PROJECT ROW LOCK: ProjectRepository::findByIdForUpdate($projectId)
2. FILE ROW LOCK:    FileRepository::findByIdForUpdate($projectId, $fileId)
===================================================================
```

### Binding Lock Rules:
1. **Universal Project-First Acquisition:** Every lifecycle operation (`replace`, `purge`, `trash`, `restore`) and every reconciliation path **MUST acquire `ProjectRepository::findByIdForUpdate($projectId)` FIRST, and `FileRepository::findByIdForUpdate($projectId, $fileId)` SECOND**.
   - *Rationale:* Because any file might contain an interrupted `replace` or `purge` operation requiring inline quota adjustment, every operation must hold the Project lock before locking the File row.
2. **Mathematical Deadlock Proof:** Because all transactions acquire resources in the strict linear order `Project -> File`, circular wait chains are impossible ($A \rightarrow B \wedge B \rightarrow A$ cannot occur). Transactions on different projects never contend.

---

## 8. Reconstructible Replacement Protocol & Recovery Matrix

Replacement replaces an active file with a new physical payload while marking the old file trashed. If a crash occurs, all data required to finish or abandon the replacement must survive in the database.

### 8.1 Complete Replacement Data Model in `file_operations`
The `file_operations` record persists the complete set of parameters required to reconstruct both records:

- `file_id`: ID of the old file being replaced.
- `storage_key`: Physical storage key of the old file.
- `target_storage_key`: Physical storage key of the newly uploaded payload (already streamed to disk in `active/`).
- `new_original_name`: Client-provided filename for the new file.
- `new_mime_type`: Client-provided MIME type for the new file.
- `new_size_bytes`: Verified size in bytes of the new file.
- `new_visibility`: Client-provided visibility (`public` or `private`).

---

### 8.2 Exhaustive Replacement Recovery Matrix ($4 \times 4 = 16$ States)

When reconciling an active `replace` operation, M07 queries `StorageBackend::locateObject` for both keys:

| Old Key Location | New Key Location | Physical Reality | Deterministic Recovery Action | Classification |
|---|---|---|---|---|
| **`ACTIVE`** | **`ACTIVE`** | Physical move of old file was not started. | **Roll-Forward:** Move old file to `trash/`; update old metadata to `trashed`; create new file record; apply `UsageDelta::replace(newSize)`; delete journal row; commit. | `Proven by Protocol` |
| **`BOTH`** | **`ACTIVE`** | Old file move crashed between link to trash and unlink from active. | **Repair & Roll-Forward:** Clean old file from `active/` via `ensureTrashClean`; execute standard Roll-Forward. | `Proven by Protocol` |
| **`TRASH`** | **`ACTIVE`** | Old file move completed; Tx 2 crashed before commit. | **Roll-Forward:** Update old metadata to `trashed`; create new file record; apply `UsageDelta::replace(newSize)`; delete journal row; commit. | `Proven by Protocol` |
| **`NONE`** | **`ACTIVE`** | Old file disappeared from disk before replace completed! | **UNRECOVERABLE ANOMALY:** Stop immediately. Do not guess. Raise `LifecycleConsistencyException` for administrative investigation. | `Explicit Anomaly` |
| Any | **`NONE`** | New uploaded payload disappeared from disk! | **UNRECOVERABLE ANOMALY:** Stop immediately. Raise `LifecycleConsistencyException`. | `Explicit Anomaly` |
| Any | **`TRASH`** | New file payload found in trash directory! | **UNRECOVERABLE ANOMALY:** Stop immediately. Raise `LifecycleConsistencyException`. | `Explicit Anomaly` |
| Any | **`BOTH`** | New file payload found in both active and trash! | **UNRECOVERABLE ANOMALY:** Stop immediately. Raise `LifecycleConsistencyException`. | `Explicit Anomaly` |

---

## 9. The Create Protocol, Idempotency & Uncertain Commits

Create operates on a new file that does not yet possess a database identity.

### 9.1 Detailed Failure Boundaries for Create

1. **Crash During Upload Streaming:** Incomplete payload in `.tmp/`. Cleaned up by temporary directory cleanup. Zero database or quota impact.
2. **Crash After Streaming, Before DB Transaction:** Physical payload in `active/` is an unreferenced orphan under a unique 32-byte key. Reclaimed safely by administrative storage sweep (`bin/filevault storage:sweep-orphans`).
3. **Crash Inside DB Transaction Before Commit:** InnoDB rolls back. Physical file is an unreferenced orphan. Zero quota impact.
4. **Indeterminate Commit Outcome (`commit()` throws `\PDOException`):** **Destructive compensation is strictly forbidden.** M07 raises `LifecycleConsistencyException`, preserving physical data.
5. **Idempotency Decision:**
   - *Baseline v1 Guarantee (No Idempotency Key):* At-most-once POST semantics. If an upload disconnects with an uncertain outcome, a blind client retry creates a second distinct file.
   - *Proposed Idempotency Extension for M05/M02:* Proposed `file_creation_idempotency` table mapping `(project_id, idempotency_key)` to `file_id`. If supplied, retrying with the same key returns the existing `FileRecord` without streaming a second file.

---

## 10. Proof of Exactly-Once Quota Effects

The table below documents metadata, intent, physical storage, and quota state across every lifecycle transition, proving that quota deltas are applied exactly once:

| Operation | Metadata Pre-State | Intent State (Tx 1) | Physical Storage State | Final Metadata State | Exact Usage Delta Applied | Transaction Applying Delta | Duplicate Prevention Proof |
|---|---|---|---|---|---|---|---|
| **Create** | None | None | File in `active/` | `status = 'active'`, size = $S$ | `UsageDelta::newFile(S)` $(+1, +S)$ | Single Creation Transaction | Applied atomically with file row insertion. If transaction rolls back, delta rolls back. |
| **Replace** | Old: `active`, size = $S_{old}$ | `pending replace`, new size = $S_{new}$ | Old in `TRASH`; New in `ACTIVE` | Old: `trashed`; New: `active` | `UsageDelta::replace(S_{new})` $(+1, +S_{new})$ | Completion Transaction (Tx 2) | Project lock serializes delta. Applied atomically with `file_operations` deletion. Old file remains counted (trashed files count). |
| **Trash** | `active` | `pending trash` | File in `TRASH` | `status = 'trashed'` | `UsageDelta::trash()` $(0, 0)$ | Completion Transaction (Tx 2) | Trashed files continue counting toward quota. Zero quota delta. |
| **Restore** | `trashed` | `pending restore` | File in `ACTIVE` | `status = 'active'` | `UsageDelta::restore()` $(0, 0)$ | Completion Transaction (Tx 2) | Restored files were already counted. Zero quota delta. |
| **Purge** | `trashed`, size = $S$ | `pending purge` | File in `NONE` | Row DELETED from `files` | `UsageDelta::purge(S)` $(-1, -S)$ | Completion Transaction (Tx 2) | Project lock serializes update. Applied atomically with `DELETE FROM files` and `file_operations` deletion. A retry finds row does not exist; returns 404 with 0 delta. |

---

## 11. Storage Backend Contract Requirements (M06 Proposal)

The proposed M06 primitives must provide concrete, verifiable behaviors:

1. **`locateObject(int $projectId, string $storageKey): ObjectLocation`:**
   - Must inspect both `projects/{id}/active/{key}` and `projects/{id}/trash/{key}`.
   - Throws `StorageOperationException` on permission errors or unreadable storage paths. (Reconciliation catches this and **never clears the journal**).
2. **`ensureTrashClean(int $projectId, string $storageKey): void`:**
   - If object exists in both `active/` and `trash/`, unlinks the `active/` path, leaving the object safely in `trash/`.
3. **`ensureActiveClean(int $projectId, string $storageKey): void`:**
   - If object exists in both `active/` and `trash/`, unlinks the `trash/` path, leaving the object safely in `active/`.
4. **Namespace Isolation Assumption:**
   - FileVault assumes exclusive ownership of its storage root. No external processes or tools modify `active/` or `trash/` paths directly.

---

## 12. Public Interface Specification

```php
namespace FileVault\Lifecycle;

use FileVault\Persistence\Database;
use FileVault\Persistence\Repository\FileRepository;
use FileVault\Persistence\Repository\ProjectRepository;
use FileVault\Persistence\Repository\FileOperationRepository;
use FileVault\Project\ProjectPolicy;
use FileVault\Storage\StorageBackend;
use FileVault\Persistence\Record\FileRecord;
use FileVault\Lifecycle\Exception\LifecycleException;
use FileVault\Lifecycle\Exception\InvalidLifecycleOperationException;
use FileVault\Lifecycle\Exception\LifecycleConsistencyException;
use FileVault\Project\Exception\QuotaExceededException;
use FileVault\Project\Exception\ProjectNotFoundException;
use FileVault\Project\Exception\ProjectNotOperationalException;

class FileLifecycle
{
    private FileRepository $fileRepository;
    private ProjectRepository $projectRepository;
    private FileOperationRepository $operationRepository;
    private ProjectPolicy $projectPolicy;

    public function __construct(
        private readonly Database $database,
        private readonly StorageBackend $storageBackend,
        ?ProjectPolicy $projectPolicy = null
    ) {
        $this->fileRepository = $this->database->createFileRepository();
        $this->projectRepository = $this->database->createProjectRepository();
        $this->operationRepository = $this->database->createFileOperationRepository();
        $this->projectPolicy = $projectPolicy ?? new ProjectPolicy();
    }

    public function create(
        int $projectId,
        $sourceStream,
        string $originalName,
        string $mimeType,
        string $visibility
    ): FileRecord;

    public function replace(
        int $projectId,
        int $fileId,
        $sourceStream,
        string $newOriginalName,
        string $newMimeType,
        string $newVisibility
    ): FileRecord;

    public function trash(int $projectId, int $fileId): void;

    public function restore(int $projectId, int $fileId): void;

    public function purge(int $projectId, int $fileId): void;
}
```

---

## 13. Testing Strategy

M07 test suites must verify all lifecycle paths, authoritative quota checks, concurrency boundaries, and crash-recovery paths:

### 13.1 Concurrency & In-Transaction Execution Tests
- Verification of mandatory lock order: all operations acquire Project lock before File lock.
- Verification that physical I/O in Tx 2 executes while row locks are held.
- Verification that competing requests block on `SELECT ... FOR UPDATE` during physical I/O.

### 13.2 Crash & Divergence Simulation Tests
- Simulation of Tx 2 crash during physical `link()`/`unlink()`: verify rollback in MySQL, lock release, and successful inline reconciliation by next request.
- Simulation of crashed restore before Tx 2 commit: verify subsequent purge detects `ACTIVE` and aborts immediately (Hazard B fix).
- Simulation of crashed purge after unlink: retry detects `pending purge` and completes metadata deletion and quota release exactly once.
- Simulation of crashed replace across all 16 storage location combinations: verify deterministic roll-forward or safe abort.

---

## 14. Non-Goals, Residual Risks & Explicit Assumptions

### 14.1 Explicit Non-Goals
- Two-phase commit (2PC) or distributed transaction coordinators across filesystem and database.
- Continuous background queue daemons or polling workers for v1.
- Resumable or chunked multi-part upload orchestration.

### 14.2 Residual Risks & Limitations
1. **Unaccessed Crashed Files:** If a process crashes and no client request ever accesses that file or project again, the pending record remains in `file_operations` until an administrator executes the offline CLI command (`bin/filevault lifecycle:reconcile`).
2. **Orphaned Uploads on Create Crash:** If a client disconnects after upload streaming succeeds but before the database transaction opens, an unreferenced physical file remains on disk until an administrative sweep cleans it up (`bin/filevault storage:sweep-orphans`).
3. **Storage Latency During Lock:** Because local directory operations occur inside Tx 2, severe disk I/O wait or NFS latency directly impacts lock hold times. `innodb_lock_wait_timeout` must be configured appropriately.

### 14.3 Required Governance Approvals
To implement M07, the FileVault Architect, M05 Module Owner, and M06 Module Owner must formally approve:
1. **M05 Contract Extensions:**
   - Migration `004_create_file_operations.sql` (`file_operations` table).
   - Method `FileRepository::findByIdForUpdate(int $projectId, int $fileId): ?FileRecord`.
   - New `FileOperationRepository` factory and class.
2. **M06 Contract Extensions:**
   - Method `StorageBackend::locateObject(int $projectId, string $storageKey): ObjectLocation`.
   - Method `StorageBackend::ensureTrashClean(int $projectId, string $storageKey): void`.
   - Method `StorageBackend::ensureActiveClean(int $projectId, string $storageKey): void`.
   - Enum `ObjectLocation` (`ACTIVE`, `TRASH`, `BOTH`, `NONE`).

---

## 15. Acceptance Criteria

M07 is acceptable only when:

1. **Formal Architectural Approvals:** M05 and M06 extensions are approved and scheduled.
2. **Mandatory Lock Order Enforced:** All operations and reconciliation paths acquire Project row lock first and File row lock second.
3. **Physical-I/O Row-Lock Fencing:** Physical mutations in Tx 2 occur while exclusive row locks are actively held, eliminating live-process races.
4. **Reconstructible Replacement:** Replacement persists all new and old file metadata, storage keys, and quota deltas in `file_operations` prior to physical I/O.
5. **No Ghost Active Files in Purge:** Purge aborts if an object exists in `active/` or `both`.
6. **Causal Purge Proof:** Missing data is only accepted as purged if an active `pending purge` journal record proves it was unlinked by this operation.
7. **Exactly-Once Quota Accounting:** Project usage deltas are applied atomically within the completion transaction.
8. **No Continuous Daemon Dependency:** Operates fully within PHP request-response lifecycle and shared-hosting constraints.
9. **Implementability:** Complete, deterministic sequences implementable in native PHP 8.4.