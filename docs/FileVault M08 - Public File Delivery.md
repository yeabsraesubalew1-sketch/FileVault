# FileVault M08 - Public File Delivery

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Http\Controller`  
**Architectural Role:** High-Performance Public Asset Retrieval, Content-Disposition, and Edge Caching  

---

## 1. Overview & Purpose

The Public File Delivery module provides secure, high-performance HTTP asset streaming for public active files without exposing internal disk paths or requiring project API keys.

It generates immutable delivery URLs, handles browser embedding versus forced downloads, emits aggressive CDN caching headers, and enforces project operational status boundaries.

---

## 2. Directory Structure

```text
src/Http/Controller/
└── PublicDeliveryController.php             # Handles /files/{storageKey} and /f/{id} delivery
```

---

## 3. Implemented Routes & Contracts

### Endpoints
* `GET /files/{storageKey:.*}`: Primary content-addressed delivery route (e.g. `/files/2026/10/f1ab51a6...`).
* `GET /f/{id}`: Convenient numeric ID delivery alias (e.g. `/f/142`).

### `PublicDeliveryController` (`FileVault\Http\Controller\PublicDeliveryController`)
* `public function deliver(Request $request, string $storageKey): Response`:
  1. Sanitizes storage key and queries metadata from `FileRepository`.
  2. Enforces access rules:
     - Returns `404 Not Found` if file record does not exist.
     - Returns `404 Not Found` if file status is `trashed`.
     - Returns `404 Not Found` if file visibility is `private`.
  3. Verifies owning Project status via `ProjectRepository`:
     - Returns `404 Not Found` if the project has status `disabled`.
  4. Checks physical file presence via `StorageBackend::exists`. If missing on disk, returns `404 Not Found` with a logged storage inconsistency event.
  5. Determines delivery mode:
     - If query parameter `download=1` is present, appends `Content-Disposition: attachment; filename="{original_name}"`.
     - Otherwise, emits `Content-Disposition: inline; filename="{original_name}"` for in-browser rendering (`<img>`, `<video>`, `<audio>`, PDFs).
  6. Emits caching headers:
     - `Cache-Control: public, max-age=31536000, immutable`
     - `ETag: "{fileId}-{sizeBytes}-{updatedAtTimestamp}"`
     - `Content-Type: {mime_type}`
     - `Content-Length: {size_bytes}`
  7. Streams file bytes directly from storage backend to client.

---

## 4. CDN & Edge Optimization (Cloudflare Ready)

1. **Immutable Content URLs**: Because `storage_key` contains content and date hashes, files are treated as immutable objects. Edge CDNs (such as Cloudflare) can safely cache assets for 1 year (`max-age=31536000`).
2. **Zero API Key Requirement**: Public file delivery does not require or evaluate `X-Api-Key`, allowing assets to be embedded directly into client websites (`<img src="https://filevault.example.com/files/..." />`).
3. **Range & Keep-Alive Support**: Leverages Apache HTTP keep-alive and chunked streaming to reduce TLS handshake overhead for media playback.
