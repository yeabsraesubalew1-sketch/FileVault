# FileVault

> **A high-performance, lightweight, multi-tenant file and object storage microservice built for shared hosting and side projects.**

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![CI/CD](https://img.shields.io/badge/Deploy-GitHub%20Actions%20%2B%20FTPS-2088FF?style=flat-square&logo=githubactions&logoColor=white)]()
[![CDN Ready](https://img.shields.io/badge/CDN-Cloudflare%20Compatible-F38020?style=flat-square&logo=cloudflare&logoColor=white)]()
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)

---

## The Backstory: Turning Leftover Shared Hosting into a Microservice

Every developer with side projects faces the same recurring dilemma:
> *"Where do I store user uploads, avatars, images, and documents without racking up AWS S3, Cloudflare R2, or Supabase storage bills with credit cards attached?"*

This project was born out of an opportunity. I was granted access to a paid **Bluehost** shared hosting account to build and modify websites. When the work wrapped up, I was allowed to keep using the hosting plan.

Most people let excess shared hosting sit idle or host a basic static WordPress site. Instead, I thought: **Why not turn this paid LAMP server into a private, S3-like object storage service for all my past, present, and future side projects?**

The catch? Shared hosting comes with strict constraints:
- Restricted memory and execution limits.
- No root or SSH daemon access.
- Deployments through cPanel / FTPS.
- Running alongside Apache `mod_rewrite` and MySQL.

Rather than trying to force heavy frameworks like Laravel or Symfony onto a shared server, I engineered **FileVault** from scratch with **PHP 8.2+ (strict types)**, a custom micro-kernel, raw PDO with transaction guarantees, binary MIME inspection, and an automated GitHub Actions CI/CD deployment pipeline.

The result is a clean, battle-tested microservice that centralizes file storage across all my applications.

---

## Features

- **Zero-Bloat Micro-Kernel**: Custom-built HTTP routing, PSR-4 autoloading, dependency injection, and middleware pipeline with zero heavy framework overhead.
- **Multi-Tenant Project Isolation**: Host storage for multiple independent projects. Each project gets isolated quotas (max storage bytes, max file count, per-file size limits) and operational statuses (`active`, `restricted`, `disabled`).
- **Cryptographic API Key Security**: Server-to-server authentication via `X-Api-Key`. Keys are split into a public lookup ID and a cryptographically salted binary secret hash (`hash_equals`). Plaintext keys are never stored.
- **Defense-in-Depth Validation**:
  - Real binary MIME detection via `finfo_buffer` (never trusts client headers).
  - Strict extension allowlisting across images, docs, audio, and video.
  - Hard blocks on scripts (`.php`, `.sh`, `.exe`) and macro documents (`.docm`, `.xlsm`).
  - Rejection of double-extension attacks (e.g., `payload.php.png`).
- **Safe File Lifecycle & Trash Isolation**:
  - Content-addressed, partitioned storage paths (`YYYY/MM/<hash>`).
  - Soft-delete (Trash) immediately reclaims storage quota for the project and revokes public delivery.
  - One-click restore or permanent purge with physical disk cleanup.
- **CDN & Public Delivery**:
  - Immutable public file delivery via `/files/{storage_key}` and `/f/{id}`.
  - Instant forced downloads with `?download=1`.
  - Aggressive HTTP `Cache-Control` and `Expires` headers preconfigured for Cloudflare caching.
- **Dual User Interfaces**:
  - **Admin Control Panel (`/admin`)**: Web portal for provisioning projects, configuring quotas, and generating/revoking API keys.
  - **Interactive Client App (`/client`)**: Ready-to-use web dashboard with drag-and-drop uploads, live quota visualization progress bars, and an asset gallery.
- **Developer & Agent Friendly**: Includes a complete CLI (`bin/console.php`), database migration runner, and integration guides with copy-paste SDK snippets for TypeScript, Python, and PHP.

---

## Architecture

```text
               ┌────────────────────────────────────────────────────────┐
               │         Consuming Applications (Side Projects)         │
               │        (Node.js / Python / PHP / Mobile Backends)      │
               └───────────────────────────┬────────────────────────────┘
                                           │ HTTPS + X-Api-Key Header
                                           ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                                 FileVault Microservice                                 │
│                                                                                        │
│   ┌────────────────────┐      ┌─────────────────────────┐     ┌────────────────────┐   │
│   │    Admin Portal    │      │    HTTP Router / API    │     │   Client Web App   │   │
│   │     (`/admin`)     │      │      (`/api/v1/*`)      │     │    (`/client`)     │   │
│   └─────────┬──────────┘      └────────────┬────────────┘     └─────────┬──────────┘   │
│             │                              │                            │              │
│             ▼                              ▼                            ▼              │
│   ┌────────────────────────────────────────────────────────────────────────────────┐   │
│   │                   Middleware & Security Pipeline                               │   │
│   │   • ApiKey Verification (hash_equals)  • Rate Limiter   • Project Quota Guard  │   │
│   └────────────────────────────────────────┬───────────────────────────────────────┘   │
│                                            │                                           │
│   ┌────────────────────────────────────────▼───────────────────────────────────────┐   │
│   │                          Domain & Lifecycle Services                           │   │
│   │   • MIME Inspection (finfo)  • Storage Partitioning  • Quota Accounting        │   │
│   └────────────────────┬──────────────────────────────────────┬────────────────────┘   │
└────────────────────────┼──────────────────────────────────────┼────────────────────────┘
                         │                                      │
                         ▼                                      ▼
              ┌─────────────────────┐                ┌─────────────────────┐
              │    MySQL Database   │                │ Local Disk Storage  │
              │  (Metadata & Quotas)│                │ (Partitioned Blobs) │
              └─────────────────────┘                └──────────┬──────────┘
                                                                │
                                                                ▼
                                                ┌───────────────────────────────┐
                                                │      Public File Delivery     │
                                                │  `/files/{storage_key}` + CDN │
                                                └───────────────────────────────┘
```

---

## API Quick Reference

All API endpoints are scoped under `/api/v1/*` and require server-to-server authentication via the `X-Api-Key` header.

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/health` | System health check and database connectivity. |
| `GET` | `/api/v1/project` | Fetch project details, quota limits, and current usage. |
| `POST` | `/api/v1/files` | Upload a new file (`multipart/form-data`). |
| `GET` | `/api/v1/files` | Paginated list of active files (`?limit=50&offset=0`). |
| `GET` | `/api/v1/files/{id}` | Retrieve metadata for a specific file. |
| `GET` | `/api/v1/files/{id}/download` | Stream authenticated download (works for private/trashed files). |
| `DELETE` | `/api/v1/files/{id}` | Soft-delete file (moves to trash & frees quota). |
| `GET` | `/api/v1/files/trash` | List trashed files for the project. |
| `POST` | `/api/v1/files/{id}/restore` | Restore file from trash back to active storage. |
| `DELETE` | `/api/v1/files/{id}/purge` | Permanently purge file from database and disk. |
| `GET` | `/files/{storage_key}` | **Public Delivery**: Stream asset inline for `<img>`, `<video>`, or PDFs. |
| `GET` | `/files/{storage_key}?download=1`| **Forced Download**: Triggers browser file save prompt. |

---

## Integration Examples

### Node.js / TypeScript (Native Fetch)
```typescript
import fs from 'node:fs';

const FILEVAULT_URL = 'https://filevault.yourdomain.com';
const API_KEY = process.env.FILEVAULT_API_KEY!;

// Upload a file
async function uploadFile(filePath: string, filename: string) {
  const fileBuffer = fs.readFileSync(filePath);
  const formData = new FormData();
  formData.append('file', new Blob([fileBuffer]), filename);

  const res = await fetch(`${FILEVAULT_URL}/api/v1/files`, {
    method: 'POST',
    headers: { 'X-Api-Key': API_KEY },
    body: formData,
  });

  const data = await res.json();
  if (!res.ok) throw new Error(`Upload failed: ${data.error?.message}`);
  
  console.log('Public URL:', data.file.url);
  return data.file;
}
```

### Python (Requests)
```python
import os, requests

FILEVAULT_URL = "https://filevault.yourdomain.com"
API_KEY = os.environ.get("FILEVAULT_API_KEY")

headers = {"X-Api-Key": API_KEY}

def upload_file(local_path: str):
    with open(local_path, "rb") as f:
        files = {"file": (os.path.basename(local_path), f)}
        res = requests.post(f"{FILEVAULT_URL}/api/v1/files", headers=headers, files=files)
    
    data = res.json()
    if res.status_code != 201:
        raise Exception(f"Upload failed: {data.get('error', {}).get('message')}")
    return data["file"]
```

### PHP (cURL)
```php
<?php

$filevaultUrl = 'https://filevault.yourdomain.com';
$apiKey = getenv('FILEVAULT_API_KEY');

$ch = curl_init($filevaultUrl . '/api/v1/files');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $apiKey],
    CURLOPT_POSTFIELDS => ['file' => new CURLFile('/path/to/receipt.pdf')],
]);

$response = curl_exec($ch);
$result = json_decode($response, true);
curl_close($ch);

echo "Stored file: " . $result['file']['storage_key'];
```

---

## CLI Management Console

FileVault includes a zero-dependency CLI script (`bin/console.php`) for server management, automation, and CI/CD operations:

```bash
# Run database migrations
php bin/console.php migrate

# Create a new project with custom quotas
php bin/console.php project:create "PortfolioApp" --max-file-size=25M --max-file-count=5000 --max-storage=5G

# List all projects and monitor storage consumption
php bin/console.php project:list

# Generate an API key for a project (outputs plaintext key once)
php bin/console.php key:create 1 "Production Backend Key"

# List active API keys for a project
php bin/console.php key:list 1

# Revoke an API key instantly
php bin/console.php key:revoke 4
```

---

## Local Development Setup

### 1. Requirements
- **PHP 8.2+** with extensions: `pdo_mysql`, `fileinfo`, `mbstring`, `json`.
- **MySQL 8.0+** or MariaDB.
- **Composer** (for dev autoloader and PHPUnit).

### 2. Clone & Install
```bash
git clone https://github.com/your-username/FileVault.git
cd FileVault
composer install
```

### 3. Environment Configuration
Copy `.env.example` to `.env` and fill in your database credentials:
```bash
cp .env.example .env
```
```env
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=filevault
DB_USERNAME=root
DB_PASSWORD=secret

STORAGE_ROOT=/absolute/path/to/FileVault/storage

ADMIN_USERNAME=admin
ADMIN_PASSWORD=change_this_to_a_secure_password
```

### 4. Run Migrations & Seed Project
```bash
# Execute schema migrations
php bin/console.php migrate

# Create your first project & API key
php bin/console.php project:create "TestProject"
php bin/console.php key:create 1 "Dev Key"
```

### 5. Launch Servers
Start the main API server:
```bash
php -S localhost:8000 -t public
```

*(Optional)* Launch the standalone interactive client interface:
```bash
php -S localhost:8080 -t client
```
Visit `http://localhost:8080` in your browser, enter your generated API key, and test uploads, previews, and quota tracking!

---

## Production Deployment (Bluehost / Shared Hosting)

FileVault is engineered to run seamlessly on standard cPanel/Apache shared hosting.

### Apache & Directory Layout
1. Point your domain or subdomain document root to the project root or configure `.htaccess`.
2. The root `.htaccess` guards all sensitive directories (`.env`, `src/`, `storage/`, `database/`, `vendor/`) with `403 Forbidden` while routing all traffic to `public/index.php`.
3. Client dashboard remains accessible at `/client` without bypassing security.

### PHP Tuning via `.user.ini`
To support large file uploads up to 100MB+ on Bluehost:
```ini
upload_max_filesize = 128M
post_max_size = 128M
memory_limit = 256M
max_execution_time = 300
```

### Automated GitHub Actions Deploy via FTPS
The repository includes a ready-to-run deployment pipeline in [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml). Whenever code is pushed to `master` or the production branch:
1. GitHub Actions connects to Bluehost over encrypted **FTPS** (`port 21`).
2. Syncs updated code automatically.
3. Automatically protects production `.env`, physical file blobs (`storage/projects/**`), and logs from being overwritten.

Add the following GitHub Repository Secrets:
- `FTP_SERVER`: Bluehost FTP hostname / IP
- `FTP_USERNAME`: Bluehost FTP user account
- `FTP_PASSWORD`: Bluehost FTP password

---

## Security Architecture

| Security Layer | Implementation Detail |
| :--- | :--- |
| **API Keys** | 128-bit public ID + 256-bit cryptographically random secret. Stored as binary SHA-256 hashes and compared using constant-time `hash_equals()`. |
| **MIME Validation** | Reads physical file header bytes via PHP `finfo` buffer rather than trusting user-submitted `Content-Type` headers. |
| **Blocked Executables** | Hard blacklist on `.php`, `.phar`, `.sh`, `.exe`, `.py`, `.js`, `.dll`, etc. |
| **Macro Malware Protection** | Prohibits macro-enabled formats (`.docm`, `.xlsm`, `.pptm`) to protect client downloaders. |
| **Double Extension Defense** | Rejects trick extensions like `avatar.php.png` or `invoice.sh.pdf`. |
| **Storage Partitioning** | Files are stored under hashed, date-partitioned paths outside the public web root. Direct file execution on the server is physically impossible. |
| **Rate Limiting** | Sliding window rate limiter to mitigate denial-of-service and brute-force key attempts. |

---

## Testing

FileVault is covered by a suite of unit and integration tests across all modules (API keys, project quotas, storage backends, HTTP routing, and file lifecycle):

```bash
./vendor/bin/phpunit
```

---

## License

This project is licensed under the [MIT License](LICENSE).
