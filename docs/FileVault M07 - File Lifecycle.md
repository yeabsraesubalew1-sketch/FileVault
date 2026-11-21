# FileVault M07 - File Lifecycle

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Lifecycle`  
**Architectural Role:** File Upload, Replacement, Soft Deletion, Restoration, and Permanent Purge Orchestration  

---

## 1. Overview & Purpose

The File Lifecycle module orchestrates the complete operational lifecycle of files in FileVault.

It coordinates between HTTP uploads, physical storage blobs (M06), database metadata (M05), and project quota accounting (M04), ensuring transactional consistency and safe lifecycle state transitions.

---

## 2. Directory Structure

```text
src/Lifecycle/
├── FileLifecycle.php                        # Domain orchestrator for file operations
└── Exception/
    ├── InvalidLifecycleOperationException.php
    ├── LifecycleConsistencyException.php
    └── LifecycleException.php
```

---

## 3. Implemented Components & Contracts

### `FileLifecycle` (`FileVault\Lifecycle\FileLifecycle`)

The central service executing file mutations:

#### 1. Upload (`create`)
* `public function create(int $projectId, $sourceStream, string $originalName, string $mimeType, string $visibility): FileRecord`
* Sequence:
  1. Stores stream payload to disk via `StorageBackend::store`.
  2. Inserts active file record in MySQL `files` table.
  3. Increments project storage usage and file count via `ProjectRepository::updateUsage`.
  4. Returns the created `FileRecord`.
  5. *Rollback Guarantee*: If the database insert fails, the stored physical blob is immediately deleted to avoid orphaned files.

#### 2. Soft Delete (`moveToTrash`)
* `public function moveToTrash(int $fileId, int $projectId): void`
* Sequence:
  1. Validates that file exists, belongs to `$projectId`, and has status `active`.
  2. Updates file status to `trashed` and records `trashed_at` timestamp.
  3. Immediately decrements project usage by `$file->sizeBytes` and decrements file count by 1.
  4. Trashed files become inaccessible via public `/files/*` URLs, but remain downloadable via authenticated `/api/v1/files/{id}/download`.

#### 3. Restore (`restoreFromTrash`)
* `public function restoreFromTrash(int $fileId, int $projectId): void`
* Sequence:
  1. Validates that file exists, belongs to `$projectId`, and has status `trashed`.
  2. Checks project quota to verify re-adding `$file->sizeBytes` will not exceed limits.
  3. Updates file status back to `active` and clears `trashed_at`.
  4. Increments project usage counters and restores public URL delivery.

#### 4. Replace (`replace`)
* `public function replace(int $fileId, int $projectId, $sourceStream, string $newMimeType): FileRecord`
* Sequence:
  1. Stores new physical blob via `StorageBackend::store`.
  2. Calculates size delta (`newSize - oldSize`).
  3. Updates storage key, MIME type, and size in database.
  4. Adjusts project quota usage delta.
  5. Unlinks the old physical file blob from disk.

#### 5. Permanent Purge (`purge`)
* `public function purge(int $fileId, int $projectId): void`
* Sequence:
  1. Validates file exists and belongs to `$projectId`.
  2. If file was active (not trashed), decrements quota counters.
  3. Unlinks physical blob from disk via `StorageBackend::delete`.
  4. Permanently deletes record from MySQL `files` table.

#### 6. Authenticated Download Stream (`downloadStream`)
* `public function downloadStream(int $fileId, int $projectId)`: Returns open read stream for any file belonging to the project, regardless of whether it is marked private or trashed.

---

## 4. Key Invariants & Lifecycle Rules

1. **State Transition Integrity**: A file cannot be moved to trash if it is already trashed; a file cannot be restored if it is active.
2. **Zero Orphaned Disk Blobs**: If metadata persistence fails during an upload or replacement, cleanup procedures execute immediately to purge the uncommitted physical file.
3. **Immediate Quota Reclamation**: Moving a file to trash immediately frees project storage quota, giving client applications predictable storage budgeting.
