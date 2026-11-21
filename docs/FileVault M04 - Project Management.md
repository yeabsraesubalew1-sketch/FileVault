# FileVault M04 - Project Management

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Project`  
**Architectural Role:** Multi-Tenant Isolation, Quota Enforcement, and Operational Governance  

---

## 1. Overview & Purpose

The Project Management module owns project identity, operational status, storage quota governance, and usage accounting for all FileVault tenants.

Every stored object and API key belongs to a specific Project. M04 governs project-level resource ceilings and provides policies for allowing or rejecting file operations.

---

## 2. Directory Structure

```text
src/Project/
├── Project.php                             # Project domain aggregate
├── ProjectPolicy.php                       # Policy evaluating operations against status & quotas
├── ProjectQuota.php                        # Storage byte limit, file count limit, max file size
├── ProjectRepository.php                   # Persistence contract for projects
├── ProjectService.php                      # Application service for project lifecycle & accounting
├── ProjectStatus.php                       # Status Enum (active, restricted, disabled)
├── ProjectUsage.php                        # Usage tracking (current_file_count, current_storage_bytes)
├── QuotaCheckResult.php                    # Quota verification outcome DTO
├── UsageDelta.php                          # Increment/decrement resource changes DTO
└── Exception/
    ├── DuplicateProjectNameException.php
    ├── InvalidProjectNameException.php
    ├── InvalidQuotaException.php
    ├── InvalidStatusTransitionException.php
    ├── ProjectNotFoundException.php
    ├── ProjectNotOperationalException.php
    └── QuotaExceededException.php
```

---

## 3. Implemented Components & Contracts

### `Project` (`FileVault\Project\Project`)
The root domain entity:
* Properties: `public readonly int $id`, `public readonly string $name`, `public readonly DateTimeImmutable $createdAt`, `public readonly DateTimeImmutable $updatedAt`.
* `public function getStatus(): ProjectStatus`: Returns current status (`ACTIVE`, `RESTRICTED`, `DISABLED`).
* `public function getQuota(): ProjectQuota`: Storage limits.
* `public function getUsage(): ProjectUsage`: Current resource consumption.

### `ProjectQuota` (`FileVault\Project\ProjectQuota`)
Defines the resource limits for a project bucket:
* `public readonly int $maxFileSize`: Maximum allowed bytes for any single file upload (e.g. 50 MB).
* `public readonly int $maxFileCount`: Maximum active files allowed (0 for unlimited).
* `public readonly int $maxStorageBytes`: Cumulative disk bytes allowed (e.g. 10 GB; 0 for unlimited).

### `ProjectStatus` (`FileVault\Project\ProjectStatus`)
State transitions:
* `ACTIVE`: Normal operations. Uploads, downloads, and public delivery are permitted.
* `RESTRICTED`: Read-only operational mode. Existing files remain accessible, but new file uploads are rejected with `403 Forbidden`.
* `DISABLED`: Suspended project. All API operations, uploads, and public delivery links are blocked.

### `ProjectPolicy` (`FileVault\Project\ProjectPolicy`)
Business policy evaluator:
* `public function checkUpload(Project $project, int $fileSizeBytes): QuotaCheckResult`: Checks if file exceeds `maxFileSize`, if file count exceeds `maxFileCount`, and if `currentStorageBytes + fileSizeBytes` exceeds `maxStorageBytes`.
* `public function canDeliverPublicly(Project $project): bool`: Disallows delivery if project is `DISABLED`.

### `ProjectService` (`FileVault\Project\ProjectService`)
Application orchestrator:
* `public function create(string $name, ProjectQuota $quota, DateTimeImmutable $now): Project`: Validates project name syntax and uniqueness, persists new project record.
* `public function updateStatus(int $projectId, ProjectStatus $newStatus, DateTimeImmutable $now): Project`: Transitions status with validation.
* `public function updateQuota(int $projectId, ProjectQuota $newQuota, DateTimeImmutable $now): Project`: Reconfigures storage limits.
* `public function applyUsageDelta(int $projectId, UsageDelta $delta, DateTimeImmutable $now): void`: Atomically increments or decrements project file counts and storage bytes.

---

## 4. Key Invariants & Edge Cases

1. **Atomic Usage Accounting**: Storage bytes and file counts are updated via atomic SQL expressions (`current_storage_bytes = current_storage_bytes + :bytes`) to prevent race conditions during concurrent uploads.
2. **Quota Soft Reclaim on Trash**: When a file is moved to trash (M07), its size is immediately deducted from `current_storage_bytes`, restoring quota for the project.
3. **Restoration Quota Verification**: Restoring a trashed file verifies that the restored bytes will not exceed the current project quota before permitting the operation.
