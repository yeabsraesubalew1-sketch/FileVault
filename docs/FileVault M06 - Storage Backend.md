# FileVault M06 - Storage Backend

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Storage`  
**Architectural Role:** Physical Storage Abstraction, Path Partitioning, and Disk Blob Operations  

---

## 1. Overview & Purpose

The Storage Backend module provides an abstract boundary between FileVault application logic and the underlying physical storage mechanism.

In FileVault v1.0, physical blobs are stored on the local host filesystem under date-partitioned, hashed directories. The module ensures atomic writes, prevents path traversal, and prepares the system for future S3/object-storage backend swapping without requiring application changes.

---

## 2. Directory Structure

```text
src/Storage/
├── StorageBackend.php                       # Abstract storage contract interface
├── StoredObject.php                         # Result value object (storageKey, size, hash)
├── Exception/
│   ├── StorageConfigurationException.php
│   ├── StorageConflictException.php
│   ├── StorageException.php
│   ├── StorageObjectNotFoundException.php
│   └── StorageOperationException.php
└── Local/
    └── LocalFilesystemStorage.php           # Host disk implementation
```

---

## 3. Implemented Components & Contracts

### `StorageBackend` (`FileVault\Storage\StorageBackend`)
The interface satisfied by physical storage adapters:
* `public function store(int $projectId, $sourceStream): StoredObject`: Reads binary input stream, computes SHA-256 hash, writes to physical disk, and returns metadata.
* `public function retrieveStream(string $storageKey)`: Returns a read-only stream resource (`fopen($path, 'rb')`) for the given storage key.
* `public function delete(string $storageKey): void`: Permanently unlinks the physical file from disk.
* `public function exists(string $storageKey): bool`: Verifies file presence on disk.
* `public function getSize(string $storageKey): int`: Returns exact file byte size.

### `StoredObject` (`FileVault\Storage\StoredObject`)
Immutable value object returned after physical storage:
* `public readonly string $storageKey`: Unique partitioned path identifier (e.g. `2026/10/f1ab51a6...`).
* `public readonly int $sizeBytes`: Exact size of stored file in bytes.
* `public readonly string $mimeType`: Detected binary MIME type.
* `public readonly string $sha256`: Hexadecimal SHA-256 content hash.

### `LocalFilesystemStorage` (`FileVault\Storage\Local\LocalFilesystemStorage`)
Local disk implementation designed for shared hosting environments:
* **Storage Root**: Defaults to `STORAGE_ROOT` configured in `.env` (e.g. `/var/www/filevault/storage`).
* **Path Generation**: Files are partitioned by year, month, and random/content hashes:
  ```text
  {STORAGE_ROOT}/projects/{projectId}/{YYYY}/{MM}/{sha256_hash}
  ```
* **Storage Key**: Relative path identifier stored in database:
  ```text
  {YYYY}/{MM}/{sha256_hash}
  ```

---

## 4. Key Invariants & Security Protections

1. **Atomic File Writes**: Files are written first to a temporary directory (`storage/.tmp/`) and then atomically renamed (`rename()`) to their final destination once checksum and byte verification complete. This prevents partial file corruption from interrupted uploads.
2. **Path Traversal Defense**: All input `storageKey` strings are strictly sanitized. Keys containing directory traversal patterns (`../`, `..\`) or null bytes are rejected immediately with `StorageOperationException`.
3. **Directory Permissions**: Automated directory creation enforces `0750` permissions to prevent unauthorized system users on shared hosting from reading private project blobs.
4. **Stream Memory Efficiency**: Blobs are moved using 64 KB chunked stream copying (`stream_copy_to_stream`) to avoid loading large files into PHP RAM.
