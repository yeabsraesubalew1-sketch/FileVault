# FileVault — Agent & Developer Integration Guide

> **For AI Agents & External Systems:**  
> This document specifies everything required to integrate external applications with FileVault. You do not need access to FileVault's internal repository or database to use this API.

---

## 1. Overview & Architectural Role

FileVault is a high-performance, multi-tenant file and object storage microservice. External applications use FileVault as an isolated media and document storage backend instead of managing local uploads or configuring direct AWS S3 SDKs.

### Core Principles
1. **Server-Side API Key Authentication**: External applications authenticate requests via the `X-Api-Key` HTTP header. API keys belong to specific Projects with isolated storage quotas and access boundaries.
2. **Zero Client-Side Secret Exposure**: Never expose your FileVault API key in browser JavaScript or mobile frontends. All file upload, deletion, and management calls must be made from your backend server.
3. **Immutable Content Delivery**: Uploaded files receive a unique, content-addressed `storage_key`. Public file delivery URLs can be safely cached by CDNs (such as Cloudflare) indefinitely.

---

## 2. Base URL & Authentication

### Base URL
```
Production: https://your-filevault-domain.com
Local Dev:   http://localhost:8000
```

### Authentication Header
Every API request to `/api/v1/*` must include the project API key:
```http
X-Api-Key: fv_live_your_secret_api_key_here
```

---

## 3. Endpoints Specification

### 3.1 Upload File
Uploads a new file to the project bucket. Enforces project quota, file extension validation, and binary MIME inspection.

* **Method**: `POST`
* **Path**: `/api/v1/files`
* **Content-Type**: `multipart/form-data`
* **Headers**: `X-Api-Key: <key>`
* **Parameters**:
  * `file` *(required)*: Binary file payload.

#### Success Response (`201 Created`):
```json
{
  "file": {
    "id": 142,
    "project_id": 1,
    "original_name": "quarterly_earnings.xlsx",
    "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "size_bytes": 1048576,
    "storage_key": "2026/10/f1ab51a6ca25c8f157d281ea30ca1f0b5ec734c88a6528f721fa6e0113751285",
    "url": "https://your-filevault-domain.com/files/2026/10/f1ab51a6ca25c8f157d281ea30ca1f0b5ec734c88a6528f721fa6e0113751285",
    "created_at": "2026-10-15T00:00:00Z"
  }
}
```

---

### 3.2 List Files
Returns a paginated list of active files uploaded under this project.

* **Method**: `GET`
* **Path**: `/api/v1/files`
* **Headers**: `X-Api-Key: <key>`
* **Query Parameters**:
  * `limit` *(optional, integer, default: 50, max: 100)*
  * `offset` *(optional, integer, default: 0)*

#### Success Response (`200 OK`):
```json
{
  "files": [
    {
      "id": 142,
      "project_id": 1,
      "original_name": "quarterly_earnings.xlsx",
      "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "size_bytes": 1048576,
      "storage_key": "2026/10/f1ab51a6ca...",
      "url": "https://your-filevault-domain.com/files/2026/10/f1ab51a6ca...",
      "created_at": "2026-10-15T00:00:00Z"
    }
  ],
  "pagination": {
    "total": 1,
    "limit": 50,
    "offset": 0
  }
}
```

---

### 3.3 Get File Metadata
Fetches metadata for a single file by ID.

* **Method**: `GET`
* **Path**: `/api/v1/files/{id}`
* **Headers**: `X-Api-Key: <key>`

#### Success Response (`200 OK`):
```json
{
  "file": {
    "id": 142,
    "project_id": 1,
    "original_name": "quarterly_earnings.xlsx",
    "mime_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "size_bytes": 1048576,
    "storage_key": "2026/10/f1ab51a6ca...",
    "url": "https://your-filevault-domain.com/files/2026/10/f1ab51a6ca...",
    "created_at": "2026-10-15T00:00:00Z"
  }
}
```

---

### 3.4 Delete File (Soft Delete)
Moves the file to the project's trash folder and immediately reclaims the file's storage quota for the project.

* **Method**: `DELETE`
* **Path**: `/api/v1/files/{id}`
* **Headers**: `X-Api-Key: <key>`

#### Success Response (`200 OK`):
```json
{
  "success": true,
  "message": "File deleted successfully"
}
```

---

### 3.5 Public Delivery (No API Key Required)
Public file streaming is served directly using the file's `storage_key`.

* **Inline View / Image Rendering**:
  ```
  GET /files/{storage_key}
  ```
  *Serves file with appropriate `Content-Type` header for embedding in `<img>`, `<video>`, or PDFs.*

* **Forced File Download**:
  ```
  GET /files/{storage_key}?download=1
  ```
  *Appends `Content-Disposition: attachment; filename="{original_name}"` to prompt a file save dialog in the browser.*

---

### 3.6 System Health Check
* **Method**: `GET`
* **Path**: `/api/v1/health`
* **Headers**: None required
* **Response (`200 OK`)**:
  ```json
  {
    "status": "healthy",
    "database": "connected",
    "timestamp": "2026-10-15T01:30:00Z"
  }
  ```

---

## 4. File Types & Security Safeguards

### Allowed File Types
* **Images**: `.jpg`, `.jpeg`, `.png`, `.webp`, `.gif`, `.avif`, `.svg`, `.ico`, `.bmp`
* **Documents**:
  * Microsoft Word: `.docx`, `.doc`, `.dotx`, `.dot`
  * Microsoft Excel: `.xlsx`, `.xls`, `.xltx`, `.xlt`
  * Microsoft PowerPoint: `.pptx`, `.ppt`, `.potx`, `.ppsx`
  * OpenDocument: `.odt`, `.ods`, `.odp`
  * Text / Portable: `.pdf`, `.rtf`, `.csv`, `.txt`, `.json`, `.xml`, `.md`
* **Archives**: `.zip`, `.tar`, `.gz`
* **Media**: `.mp3`, `.wav`, `.ogg`, `.mp4`, `.webm`, `.mov`

### Strictly Blocked Extensions
* **Executables & Scripts**: `.php`, `.phtml`, `.phar`, `.exe`, `.sh`, `.bat`, `.cmd`, `.py`, `.js`, `.vbs`, `.msi`, `.bin`, `.dll`
* **Macro-Enabled Documents**: `.docm`, `.xlsm`, `.pptm`, `.dotm`, `.xltm`, `.potm` *(blocked to protect client machines from macro viruses)*
* **Double Extensions**: Filenames like `report.php.png` or `invoice.sh.docx` are rejected immediately.

---

## 5. Quotas, Limits & Error Handling

### Standard Error Envelope
All error responses adhere to the standard JSON structure:
```json
{
  "error": {
    "code": "ERROR_CODE_STRING",
    "message": "Human-readable explanation of failure.",
    "context": {}
  }
}
```

### Common HTTP Status Codes
| HTTP Status | Error Code | Cause & Resolution |
| :--- | :--- | :--- |
| `400 Bad Request` | `VALIDATION_FAILED` | File missing, prohibited extension, or double extension attack. |
| `401 Unauthorized` | `INVALID_API_KEY` | Missing or invalid `X-Api-Key` header. |
| `403 Forbidden` | `PROJECT_RESTRICTED` | Project is in restricted mode (cannot upload new files). |
| `403 Forbidden` | `PROJECT_DISABLED` | Project has been disabled by administrator. |
| `403 Forbidden` | `QUOTA_EXCEEDED` | File would exceed project's storage bytes quota or file count quota. |
| `404 Not Found` | `FILE_NOT_FOUND` | File ID does not exist or belongs to another project. |
| `413 Payload Too Large` | `FILE_SIZE_LIMIT_EXCEEDED` | File exceeds project's per-file maximum size or host limit. |
| `500 Server Error` | `STORAGE_ERROR` | Internal disk write failure or database error. |

### Upload Limits & Caps
* **Hosting Cap (Bluehost Choice Plus)**: Configurable up to **512 MB** via `.user.ini` / `php.ini`. Standard recommended configuration is **100 MB–256 MB**.
* **Cloudflare Free CDN Cap**: If routed through Cloudflare's free proxy, Cloudflare enforces a hard **100 MB** request limit (`HTTP 413 Payload Too Large`).

---

## 6. Ready-to-Copy Integration Examples

### Node.js / TypeScript (Native Fetch)
```typescript
import fs from 'node:fs';

const FILEVAULT_URL = 'https://your-filevault-domain.com';
const API_KEY = process.env.FILEVAULT_API_KEY!;

// 1. Upload a file
async function uploadFile(filePath: string, filename: string) {
  const fileBuffer = fs.readFileSync(filePath);
  const formData = new FormData();
  formData.append('file', new Blob([fileBuffer]), filename);

  const res = await fetch(`${FILEVAULT_URL}/api/v1/files`, {
    method: 'POST',
    headers: {
      'X-Api-Key': API_KEY,
    },
    body: formData,
  });

  const data = await res.json();
  if (!res.ok) {
    throw new Error(`Upload failed [${data.error?.code}]: ${data.error?.message}`);
  }

  return data.file; // { id, url, storage_key, original_name, ... }
}

// 2. Delete a file
async function deleteFile(fileId: number) {
  const res = await fetch(`${FILEVAULT_URL}/api/v1/files/${fileId}`, {
    method: 'DELETE',
    headers: { 'X-Api-Key': API_KEY },
  });
  return res.json();
}
```

---

### Python (Requests)
```python
import os
import requests

FILEVAULT_URL = "https://your-filevault-domain.com"
API_KEY = os.environ.get("FILEVAULT_API_KEY")

headers = {
    "X-Api-Key": API_KEY
}

# 1. Upload a file
def upload_file(local_path: str):
    with open(local_path, "rb") as f:
        files = {"file": (os.path.basename(local_path), f)}
        res = requests.post(f"{FILEVAULT_URL}/api/v1/files", headers=headers, files=files)
    
    data = res.json()
    if res.status_code != 201:
        raise Exception(f"Upload failed: {data.get('error', {}).get('message')}")
    return data["file"]

# 2. List files
def list_files(limit=20, offset=0):
    res = requests.get(f"{FILEVAULT_URL}/api/v1/files", headers=headers, params={"limit": limit, "offset": offset})
    return res.json()["files"]
```

---

### PHP (cURL)
```php
<?php

$filevaultUrl = 'https://your-filevault-domain.com';
$apiKey = getenv('FILEVAULT_API_KEY');

// 1. Upload a file
function uploadFile(string $filePath, string $filevaultUrl, string $apiKey): array
{
    $ch = curl_init($filevaultUrl . '/api/v1/files');
    $cfile = new CURLFile($filePath);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $apiKey],
        CURLOPT_POSTFIELDS => ['file' => $cfile],
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);
    if ($status !== 201) {
        throw new RuntimeException($json['error']['message'] ?? 'Upload failed');
    }

    return $json['file'];
}
```
