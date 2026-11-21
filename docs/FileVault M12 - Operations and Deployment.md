# FileVault M12 - Operations and Deployment

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Architectural Role:** Shared Hosting Runtime Adaptation, CI/CD Pipeline, CLI Tooling, and Web Server Hardening  

---

## 1. Overview & Purpose

The Operations and Deployment module defines how FileVault runs under real-world shared hosting constraints (specifically Bluehost cPanel/LAMP environment), automates code delivery via GitHub Actions and FTPS, and provides CLI administration utilities.

---

## 2. Directory Structure & Key Files

```text
├── .github/
│   └── workflows/
│       └── deploy.yml               # GitHub Actions automated FTPS deployment
├── bin/
│   ├── console.php                  # Zero-dependency administration CLI
│   └── migrate.php                  # Database migration runner CLI
├── .htaccess                        # Root Apache rewrite & access-control rules
├── public/
│   └── .htaccess                    # Public web root front-controller rewrite rules
└── .user.ini                        # PHP runtime upload and memory limits
```

---

## 3. Implemented Components & Operations

### 1. Automated GitHub Actions Deploy via FTPS (`.github/workflows/deploy.yml`)
Deploys code changes to Bluehost on push to `master`:
* Uses `SamKirkland/FTP-Deploy-Action@v4.3.5` over encrypted FTPS (port 21).
* **Exclusion Safeguards**: Explicitly excludes environment files (`.env`, `client/.env`), tests (`tests/`, `phpunit.xml`), and physical file storage (`storage/projects/**`, `storage/.tmp/**`) to ensure deployments never overwrite production data or blobs.

### 2. CLI Administration Tool (`bin/console.php`)
Provides command-line control on the server or local dev machine:
* `php bin/console.php migrate`: Executes database schema migrations in sequence.
* `php bin/console.php project:create <name> [--max-file-size=50M] [--max-file-count=1000] [--max-storage=1G]`: Provisions a project with custom quotas.
* `php bin/console.php project:list`: Displays tabular overview of all projects, quotas, and current storage consumption.
* `php bin/console.php key:create <projectId> [name]`: Issues a new cryptographically hashed API key and displays the plaintext credential once.
* `php bin/console.php key:list <projectId>`: Lists registered API keys and statuses for a project.
* `php bin/console.php key:revoke <keyId>`: Immediately revokes an API key.

### 3. Apache Front Controller & Security Hardening (`.htaccess`)
* **Root `.htaccess`**: Directs all incoming traffic into `public/`, excludes the client demo (`/client`), and blocks direct access to sensitive directories (`.env`, `src/`, `storage/`, `database/`, `vendor/`) with `403 Forbidden`.
* **Public `public/.htaccess`**: Routes non-static URLs to `public/index.php`, forwards `Authorization` and `X-Api-Key` headers to PHP, enables persistent HTTP keep-alive, and configures `mod_expires` caching for asset types.

### 4. Shared Hosting PHP Tuning (`.user.ini`)
Configured to allow high-capacity file uploads on Bluehost:
```ini
upload_max_filesize = 128M
post_max_size = 128M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300
```

---

## 4. Key Invariants & Operational Procedures

1. **Storage Persistence**: Deployment operations must never wipe or replace the `storage/projects/` directory.
2. **Environment Isolation**: Production `.env` is managed out-of-band and never committed to source control.
3. **Database Migration Safety**: Migrations are strictly additive SQL scripts (`CREATE TABLE`, `ADD COLUMN`). Destructive actions require manual approval.
