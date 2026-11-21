# FileVault M10 - Security and Abuse Controls

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Http\Security`, `FileVault\Http`  
**Architectural Role:** Multi-Layer Upload Validation, Exploit Prevention, CORS, and Directory Hardening  

---

## 1. Overview & Purpose

The Security and Abuse Controls module defends FileVault against malicious payloads, web-shell execution, MIME spoofing, cross-origin abuse, and unauthorized server filesystem access.

Because FileVault runs on shared hosting where Apache and PHP share the same execution boundary, M10 enforces multi-layered defenses before any uploaded bytes reach storage.

---

## 2. Directory Structure

```text
src/Http/
├── CorsHandler.php                          # CORS preflight evaluation and header application
└── Security/
    └── FileValidator.php                    # Binary MIME inspection, extension blacklist & whitelist
```

---

## 3. Implemented Security Controls

### 1. Binary MIME Sniffing (`FileValidator`)
* FileVault never trusts client-provided `Content-Type` headers or file extensions.
* Reads raw physical byte headers via PHP `finfo_buffer(..., FILEINFO_MIME_TYPE)`.
* Verifies that the detected binary MIME type exists in the allowed whitelist (Images, Documents, Audio, Video, Archives).
* Validates that the file extension is compatible with the detected MIME type (e.g. rejecting a `.jpg` whose binary signature indicates `application/x-dosexec`).

### 2. Prohibited Extension Blacklist
Hard blocks all script, executable, and dangerous server-side file extensions, including:
* Web scripts: `.php`, `.phtml`, `.phar`, `.php3`-`.php8`, `.phps`, `.cgi`, `.pl`, `.py`, `.sh`, `.bash`, `.js`.
* Binaries & executables: `.exe`, `.com`, `.bat`, `.cmd`, `.vbs`, `.msi`, `.bin`, `.dll`, `.so`.
* Server configuration: `.htaccess`, `.htpasswd`, `.ini`, `.env`.

### 3. Macro Malware Document Blocking
Prohibits macro-enabled Microsoft Office formats (`.docm`, `.dotm`, `.xlsm`, `.xltm`, `.xlam`, `.pptm`, `.potm`, `.ppam`, `.ppsm`, `.sldm`) to protect downstream users who download files from infected client machines.

### 4. Double-Extension Defense
Rejects trick double extensions used to bypass naive web-server rules (e.g., `avatar.php.png` or `report.sh.pdf`). If a filename contains more than one extension and any internal component matches the prohibited list, `FileValidator::validateFileName()` halts with an exception.

### 5. CORS Hardening (`CorsHandler`)
* Evaluates `OPTIONS` preflight requests:
  - Allowed Methods: `GET, POST, DELETE, OPTIONS`.
  - Allowed Headers: `Content-Type, X-Api-Key, Authorization, Accept`.
  - Max-Age: `86400` (24 hours).
* Attaches appropriate `Access-Control-Allow-Origin` headers to all API responses.

### 6. Apache Directory Access Denials (`.htaccess`)
The project root `.htaccess` rejects direct HTTP access with `403 Forbidden` (`[F,L,NC]`) for all non-public directories:
* `^.env`
* `^storage/`
* `^src/`
* `^config/`
* `^database/`
* `^vendor/`
* `^tests/`
All incoming traffic is forced into `public/index.php`.
