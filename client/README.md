# FileVault Client Demo Interface

This is a standalone, lightweight web interface designed for trying out and interacting with your FileVault instance.

It allows you to:
- Connect to your FileVault API securely (API key is kept strictly server-side).
- View project operational status and storage quota usage.
- Upload files via drag-and-drop or file picker.
- Browse uploaded files in a responsive gallery view.
- Render thumbnail image previews directly using FileVault's public file delivery URLs.
- Download uploaded files with a single click.
- Copy public asset delivery URLs to your clipboard.
- Soft-delete files from the vault.

---

## Getting Started

### 1. Start the FileVault API Server

From the project root directory, run PHP's built-in server (e.g. on port 8000):

```bash
php -S localhost:8000 -t public
```

### 2. Configure Client `.env`

The client comes with a preconfigured `.env` file pointing to:
- `FILEVAULT_API_URL=http://localhost:8000`
- `FILEVAULT_API_KEY=<your_generated_api_key>`

If you want to use a different key or URL, edit `client/.env`:

```env
FILEVAULT_API_URL=http://localhost:8000
FILEVAULT_API_KEY=fvk_eySnduHEIVZanfD_vWg_cg_8VvqEbGy01aXs1adidLXuDn_tkx1sQxfDQlIll3PaMA
```

*(You can generate new API keys at any time via `php bin/console.php key:create <projectId> <name>`)*.

### 3. Start the Client Interface

In a second terminal window, start a PHP server for the `client/` folder (e.g. on port 8080):

```bash
php -S localhost:8080 -t client
```

### 4. Open in Your Browser

Navigate to:
```
http://localhost:8080
```

You will see the dashboard with your connected project name, live quota progress bar, upload dropzone, and file gallery!
