# FileVault M09 - Admin Interface

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\Http\Controller`  
**Architectural Role:** Administrative Control Plane, Web Portal, Project Administration, and Key Provisioning  

---

## 1. Overview & Purpose

The Admin Interface module provides a protected web dashboard for system administrators.

It operates independently of the project-scoped REST API, providing administrative session authentication, CSRF defense, system health monitoring, project provisioning, quota reconfiguration, and immediate API key revocation.

---

## 2. Directory Structure

```text
src/Http/Controller/
└── AdminController.php                      # Admin portal routes, session auth, CSRF, and UI rendering
```

---

## 3. Implemented Routes & Capabilities

All administrative routes are isolated under `/admin/*`:

| Route | Method | Description |
| :--- | :--- | :--- |
| `/admin/login` | `GET` | Renders the administrator sign-in portal. |
| `/admin/login` | `POST` | Authenticates master credentials and creates secure session. |
| `/admin/logout` | `GET`/`POST`| Clears session state and destroys cookie. |
| `/admin` | `GET` | Main control plane dashboard with metrics and project list. |
| `/admin/projects` | `POST` | Provisions a new project with custom quota limits. |
| `/admin/projects/{id}/status` | `POST` | Transitions project status (`active`, `restricted`, `disabled`). |
| `/admin/projects/{id}/quota` | `POST` | Updates project storage limit, file limit, and max file size. |
| `/admin/projects/{id}/keys` | `POST` | Generates a new API key for the project. |
| `/admin/keys/{id}/revoke` | `POST` | Revokes an API key immediately. |

---

## 4. Security Architecture & Controls

1. **Independent Session Authentication**: Uses native PHP sessions with hardened cookie flags (`HttpOnly: true`, `SameSite: Lax`, `path: /`). Session cookies are strictly isolated from client-side JavaScript.
2. **Timing-Attack Resistance**: Administrator username and master password verification use `hash_equals()` with a mandatory 250ms sleep delay (`usleep(250000)`) on failed attempts to mitigate brute-force attacks.
3. **CSRF Token Validation**: Every mutating `POST` request requires a valid `csrf_token` matching `$_SESSION['admin_csrf']`. Requests with missing or mismatched tokens are rejected.
4. **Credential Revelation Boundary**: Newly created API keys reveal their plaintext secret only once via a prominent copy block. Keys cannot be re-read after creation.

---

## 5. UI Design System

* Built with a dark obsidian/carbon palette (`#090b0e`, `#101319`, `#1e2430`) and electric engineering blue accents (`#2563eb`).
* Zero external CSS frameworks or heavy JS dependencies.
* Zero emojis: Uses clean, geometric inline SVGs and uppercase monospace labels throughout.
