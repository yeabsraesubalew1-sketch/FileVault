<?php

declare(strict_types=1);

namespace FileVault\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Application\CreateApiKey;
use FileVault\ApiKey\Persistence\ApiKeyRecord;
use FileVault\ApiKey\Persistence\ApiKeyRepository;
use FileVault\Foundation\Bootstrap\App;
use FileVault\Http\Request;
use FileVault\Http\Response;
use FileVault\Project\Exception\DuplicateProjectNameException;
use FileVault\Project\Exception\InvalidProjectNameException;
use FileVault\Project\Exception\ProjectNotFoundException;
use FileVault\Project\Project;
use FileVault\Project\ProjectQuota;
use FileVault\Project\ProjectRepository;
use FileVault\Project\ProjectService;
use FileVault\Project\ProjectStatus;
use Throwable;

final class AdminController
{
    private ProjectService $projectService;
    private ProjectRepository $projectRepository;
    private ApiKeyRepository $apiKeyRepository;
    private CreateApiKey $createApiKey;
    private string $adminUsername;
    private string $adminPassword;

    public function __construct(
        private readonly App $app
    ) {
        $this->projectService = $app->getProjectService();
        $this->projectRepository = $app->getProjectRepository();
        $this->apiKeyRepository = $app->getApiKeyRepository();
        $this->createApiKey = $app->getCreateApiKey();
        $config = $app->getConfig();
        $this->adminUsername = $config->adminUsername;
        $this->adminPassword = $config->adminPassword;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            session_start();
        }

        if (empty($_SESSION['admin_csrf'])) {
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        }
    }

    private function isAuthenticated(): bool
    {
        $this->startSession();
        return !empty($_SESSION['filevault_admin_logged_in']);
    }

    private function verifyCsrf(Request $request): bool
    {
        $this->startSession();
        $token = (string) $request->input('csrf_token', '');
        $sessionToken = (string) ($_SESSION['admin_csrf'] ?? '');

        return $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }

    private function setFlash(string $key, mixed $message): void
    {
        $this->startSession();
        $_SESSION['flash_' . $key] = $message;
    }

    private function getFlash(string $key): mixed
    {
        $this->startSession();
        $flash = $_SESSION['flash_' . $key] ?? null;
        unset($_SESSION['flash_' . $key]);
        return $flash;
    }

    public function loginView(Request $request): Response
    {
        if ($this->isAuthenticated()) {
            return Response::redirect('/admin');
        }

        $error = $this->getFlash('error');
        $csrfToken = $_SESSION['admin_csrf'];

        $html = $this->renderLoginHtml($error, $csrfToken);
        return Response::html($html);
    }

    public function login(Request $request): Response
    {
        $this->startSession();

        if (!$this->verifyCsrf($request)) {
            $this->setFlash('error', 'Invalid security token. Please try again.');
            return Response::redirect('/admin/login');
        }

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        // Constant time comparison
        $validUser = hash_equals($this->adminUsername, $username);
        $validPass = hash_equals($this->adminPassword, $password);

        if (!$validUser || !$validPass) {
            usleep(250000); // 250ms delay against brute force
            $this->setFlash('error', 'Invalid admin username or password.');
            return Response::redirect('/admin/login');
        }

        session_regenerate_id(true);
        $_SESSION['filevault_admin_logged_in'] = true;
        $_SESSION['filevault_admin_user'] = $this->adminUsername;
        $this->setFlash('success', 'Logged in successfully.');

        return Response::redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $this->startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        return Response::redirect('/admin/login');
    }

    public function dashboard(Request $request): Response
    {
        if (!$this->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        $projects = $this->projectRepository->findAll(200, 0);

        // Fetch keys per project
        $keysByProject = [];
        $totalStorageBytes = 0;
        $totalFileCount = 0;
        $totalActiveKeys = 0;

        foreach ($projects as $p) {
            $keys = $this->apiKeyRepository->findByProject($p->id);
            $keysByProject[$p->id] = $keys;
            $totalStorageBytes += $p->getUsage()->currentStorageBytes;
            $totalFileCount += $p->getUsage()->currentFileCount;

            foreach ($keys as $k) {
                if ($k->status === ApiKeyStatus::ACTIVE) {
                    $totalActiveKeys++;
                }
            }
        }

        $success = $this->getFlash('success');
        $error = $this->getFlash('error');
        $newKeyFlash = $this->getFlash('new_key');
        $csrfToken = $_SESSION['admin_csrf'];

        $html = $this->renderDashboardHtml([
            'projects' => $projects,
            'keysByProject' => $keysByProject,
            'totalStorageBytes' => $totalStorageBytes,
            'totalFileCount' => $totalFileCount,
            'totalActiveKeys' => $totalActiveKeys,
            'success' => $success,
            'error' => $error,
            'newKey' => $newKeyFlash,
            'csrfToken' => $csrfToken,
            'adminUser' => $_SESSION['filevault_admin_user'] ?? 'admin',
        ]);

        return Response::html($html);
    }

    public function createProject(Request $request): Response
    {
        if (!$this->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if (!$this->verifyCsrf($request)) {
            $this->setFlash('error', 'Security token expired. Please retry.');
            return Response::redirect('/admin');
        }

        $name = trim((string) $request->input('name', ''));
        $maxStorageStr = trim((string) $request->input('max_storage', '1G'));
        $maxFileSizeStr = trim((string) $request->input('max_file_size', '50M'));
        $maxFileCount = max(0, (int) $request->input('max_file_count', '1000'));

        if ($name === '') {
            $this->setFlash('error', 'Project name is required.');
            return Response::redirect('/admin');
        }

        $maxStorage = $this->parseBytes($maxStorageStr);
        $maxFileSize = $this->parseBytes($maxFileSizeStr);

        $quota = new ProjectQuota($maxFileSize, $maxFileCount, $maxStorage);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $project = $this->projectService->create($name, $quota, $now);
            $this->setFlash('success', "Project \"{$project->name}\" created successfully (ID: {$project->id})!");
        } catch (DuplicateProjectNameException) {
            $this->setFlash('error', "A project named \"{$name}\" already exists.");
        } catch (InvalidProjectNameException $e) {
            $this->setFlash('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->setFlash('error', 'Failed to create project: ' . $e->getMessage());
        }

        return Response::redirect('/admin');
    }

    public function updateProjectStatus(Request $request, string $id): Response
    {
        if (!$this->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if (!$this->verifyCsrf($request)) {
            $this->setFlash('error', 'Security token expired. Please retry.');
            return Response::redirect('/admin');
        }

        $projectId = (int) $id;
        $targetStatus = strtolower(trim((string) $request->input('status', '')));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            match ($targetStatus) {
                'active' => $this->projectService->activate($projectId, $now),
                'restricted' => $this->projectService->restrict($projectId, $now),
                'disabled' => $this->projectService->disable($projectId, $now),
                default => throw new \InvalidArgumentException('Invalid project status specified.'),
            };

            $this->setFlash('success', "Project #{$projectId} status updated to \"{$targetStatus}\".");
        } catch (ProjectNotFoundException) {
            $this->setFlash('error', "Project #{$projectId} not found.");
        } catch (Throwable $e) {
            $this->setFlash('error', 'Status update failed: ' . $e->getMessage());
        }

        return Response::redirect('/admin');
    }

    public function updateProjectQuota(Request $request, string $id): Response
    {
        if (!$this->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if (!$this->verifyCsrf($request)) {
            $this->setFlash('error', 'Security token expired. Please retry.');
            return Response::redirect('/admin');
        }

        $projectId = (int) $id;
        $maxStorage = $this->parseBytes(trim((string) $request->input('max_storage', '1G')));
        $maxFileSize = $this->parseBytes(trim((string) $request->input('max_file_size', '50M')));
        $maxFileCount = max(0, (int) $request->input('max_file_count', '1000'));

        $quota = new ProjectQuota($maxFileSize, $maxFileCount, $maxStorage);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $this->projectService->updateQuota($projectId, $quota, $now);
            $this->setFlash('success', "Quotas updated for Project #{$projectId}.");
        } catch (ProjectNotFoundException) {
            $this->setFlash('error', "Project #{$projectId} not found.");
        } catch (Throwable $e) {
            $this->setFlash('error', 'Failed to update quota: ' . $e->getMessage());
        }

        return Response::redirect('/admin');
    }

    public function createApiKey(Request $request, string $projectId): Response
    {
        if (!$this->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if (!$this->verifyCsrf($request)) {
            $this->setFlash('error', 'Security token expired. Please retry.');
            return Response::redirect('/admin');
        }

        $pId = (int) $projectId;
        $name = trim((string) $request->input('name', 'Admin API Key'));
        if ($name === '') {
            $name = 'Default API Key';
        }

        $project = $this->projectRepository->findById($pId);
        if ($project === null) {
            $this->setFlash('error', "Project #{$pId} not found.");
            return Response::redirect('/admin');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $createdKey = $this->createApiKey->create($pId, $name, $now);

            $this->setFlash('new_key', [
                'projectId' => $pId,
                'projectName' => $project->name,
                'keyName' => $name,
                'keyId' => $createdKey->apiKey->id,
                'plaintext' => $createdKey->plaintextCredential,
            ]);

            $this->setFlash('success', "API Key created for project \"{$project->name}\"!");
        } catch (Throwable $e) {
            $this->setFlash('error', 'Failed to generate key: ' . $e->getMessage());
        }

        return Response::redirect('/admin');
    }

    public function revokeApiKey(Request $request, string $keyId): Response
    {
        if (!$this->isAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if (!$this->verifyCsrf($request)) {
            $this->setFlash('error', 'Security token expired. Please retry.');
            return Response::redirect('/admin');
        }

        $kId = (int) $keyId;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $this->apiKeyRepository->revoke($kId, $now);
            $this->setFlash('success', "API Key #{$kId} has been revoked.");
        } catch (Throwable $e) {
            $this->setFlash('error', 'Failed to revoke key: ' . $e->getMessage());
        }

        return Response::redirect('/admin');
    }

    private function parseBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '' || strtolower($val) === 'unlimited') {
            return 0;
        }

        $val = str_ireplace(['bytes', 'b'], '', $val);
        $val = trim($val);

        $last = strtolower(substr($val, -1));
        $num = (int) $val;

        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $val,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return 'Unlimited';
        }
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    private function getBasePrefix(): string
    {
        $appUrl = $this->app->getConfig()->appUrl;
        $path = parse_url($appUrl, PHP_URL_PATH);
        if (is_string($path)) {
            $prefix = rtrim($path, '/');
            if ($prefix !== '') {
                return $prefix;
            }
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName !== '') {
            $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            $candidates = [
                $scriptDir,
                preg_replace('#/public$#', '', $scriptDir),
            ];
            foreach ($candidates as $candidate) {
                if ($candidate !== null && $candidate !== '' && $candidate !== '/') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function url(string $path): string
    {
        return $this->getBasePrefix() . '/' . ltrim($path, '/');
    }

    // --------------------------------------------------------------------------
    // Views Rendering
    // --------------------------------------------------------------------------

    private function renderLoginHtml(?string $error, string $csrfToken): string
    {
        $errorAlert = $error ? '
        <div class="alert alert-error">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</span>
        </div>' : '';

        $loginAction = $this->url('/admin/login');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileVault — Control Plane Login</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090b0e;
            --surface: #101319;
            --surface-elevated: #151921;
            --border: #1e2430;
            --border-hover: #2d3648;
            --border-focus: #3b82f6;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --danger: #ef4444;
            --danger-bg: #450a0a;
            --danger-border: #7f1d1d;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', 'SF Mono', Consolas, monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-sans);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 36px 32px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
        }

        .brand-header {
            text-align: left;
            margin-bottom: 28px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 6px;
            margin-bottom: 14px;
        }

        .brand-badge svg {
            width: 20px;
            height: 20px;
            stroke: #60a5fa;
        }

        .sys-tag {
            display: block;
            font-family: var(--font-mono);
            font-size: 10.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #60a5fa;
            margin-bottom: 4px;
        }

        .brand-header h1 {
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text);
        }

        .brand-header p {
            color: var(--text-dim);
            font-size: 13px;
            margin-top: 4px;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 20px;
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: #fca5a5;
        }

        .alert svg { width: 16px; height: 16px; flex-shrink: 0; }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        input {
            width: 100%;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px;
            color: var(--text);
            font-size: 13.5px;
            font-family: var(--font-mono);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        input:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 1px var(--border-focus);
        }

        .btn-submit {
            width: 100%;
            background: var(--primary);
            border: 1px solid var(--primary-hover);
            border-radius: 6px;
            padding: 11px;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            margin-top: 8px;
            transition: background-color 0.15s, border-color 0.15s;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
        }

        .footer-note {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-dim);
        }

        .footer-note .dot-secure {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
            margin-right: 6px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand-header">
        <div class="brand-badge">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
            </svg>
        </div>
        <span class="sys-tag">System Access</span>
        <h1>FileVault Control Plane</h1>
        <p>Authenticate with administrative credentials</p>
    </div>

    {$errorAlert}

    <form method="POST" action="{$loginAction}">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus placeholder="admin" autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="Master password" autocomplete="current-password">
        </div>

        <button type="submit" class="btn-submit">Sign In</button>
    </form>

    <div class="footer-note">
        <span><span class="dot-secure"></span>SECURE NODE</span>
        <span>v1.0.0</span>
    </div>
</div>

</body>
</html>
HTML;
    }

    /**
     * @param array{
     *   projects: list<Project>,
     *   keysByProject: array<int, list<ApiKeyRecord>>,
     *   totalStorageBytes: int,
     *   totalFileCount: int,
     *   totalActiveKeys: int,
     *   success: ?string,
     *   error: ?string,
     *   newKey: ?array{projectId: int, projectName: string, keyName: string, keyId: int, plaintext: string},
     *   csrfToken: string,
     *   adminUser: string
     * } $data
     */
    private function renderDashboardHtml(array $data): string
    {
        $projects = $data['projects'];
        $keysByProject = $data['keysByProject'];
        $csrfToken = $data['csrfToken'];
        $adminUser = htmlspecialchars($data['adminUser'], ENT_QUOTES, 'UTF-8');
        $logoutUrl = $this->url('/admin/logout');
        $createProjectAction = $this->url('/admin/projects');
        $projectApiBase = $this->url('/admin/projects/');

        $flashHtml = '';
        if ($data['success']) {
            $msg = htmlspecialchars($data['success'], ENT_QUOTES, 'UTF-8');
            $flashHtml .= "
            <div class=\"banner banner-success\">
                <svg width=\"16\" height=\"16\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M5 13l4 4L19 7\"/></svg>
                <span>{$msg}</span>
            </div>";
        }
        if ($data['error']) {
            $msg = htmlspecialchars($data['error'], ENT_QUOTES, 'UTF-8');
            $flashHtml .= "
            <div class=\"banner banner-error\">
                <svg width=\"16\" height=\"16\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z\"/></svg>
                <span>{$msg}</span>
            </div>";
        }

        // Newly generated plaintext key banner
        $newKeyBanner = '';
        if ($data['newKey']) {
            $nk = $data['newKey'];
            $keyPlaintext = htmlspecialchars($nk['plaintext'], ENT_QUOTES, 'UTF-8');
            $projName = htmlspecialchars($nk['projectName'], ENT_QUOTES, 'UTF-8');
            $keyName = htmlspecialchars($nk['keyName'], ENT_QUOTES, 'UTF-8');
            $keyId = (int) $nk['keyId'];

            $newKeyBanner = "
            <div class=\"key-generated-banner\">
                <div class=\"kgb-header\">
                    <div class=\"kgb-icon\">
                        <svg width=\"18\" height=\"18\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z\"/></svg>
                    </div>
                    <div>
                        <h3>API Key Generated: {$keyName} (#{$keyId})</h3>
                        <p>Scope: <strong>{$projName}</strong> &bull; Store this secret securely. It cannot be retrieved again.</p>
                    </div>
                </div>
                <div class=\"kgb-box\">
                    <code id=\"newGeneratedKey\">{$keyPlaintext}</code>
                    <button class=\"btn-copy\" onclick=\"copyText('newGeneratedKey', this)\">
                        <svg width=\"14\" height=\"14\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3\"/></svg>
                        <span>Copy Key</span>
                    </button>
                </div>
            </div>";
        }

        // Stats
        $totalProjectsCount = count($projects);
        $totalStorageFormatted = $this->formatBytes($data['totalStorageBytes']);
        $totalFilesCountFormatted = number_format($data['totalFileCount']);
        $totalKeysCount = $data['totalActiveKeys'];

        // Projects Cards
        $projectsHtml = '';
        if (empty($projects)) {
            $projectsHtml = '
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                <h3>No Projects Configured</h3>
                <p>Initialize a project below to allocate storage quotas and generate client API keys.</p>
            </div>';
        } else {
            foreach ($projects as $p) {
                $pId = $p->id;
                $pName = htmlspecialchars($p->name, ENT_QUOTES, 'UTF-8');
                $statusVal = $p->getStatus()->value;
                $statusClass = match ($statusVal) {
                    'active' => 'status-active',
                    'restricted' => 'status-restricted',
                    'disabled' => 'status-disabled',
                    default => '',
                };

                $quota = $p->getQuota();
                $usage = $p->getUsage();

                $storagePercent = $quota->maxStorageBytes > 0 
                    ? min(100, round(($usage->currentStorageBytes / $quota->maxStorageBytes) * 100, 1))
                    : 0;

                $usedStorageStr = $this->formatBytes($usage->currentStorageBytes);
                $maxStorageStr = $this->formatBytes($quota->maxStorageBytes);
                $fileCountStr = number_format($usage->currentFileCount);
                $maxFilesStr = $quota->maxFileCount > 0 ? number_format($quota->maxFileCount) : 'Unlimited';
                $maxFileSizeStr = $this->formatBytes($quota->maxFileSize);

                $keys = $keysByProject[$pId] ?? [];
                $keysCount = count($keys);

                // Keys list HTML
                $keysRowsHtml = '';
                if (empty($keys)) {
                    $keysRowsHtml = '<tr><td colspan="6" class="empty-keys">No API keys registered for this project.</td></tr>';
                } else {
                    foreach ($keys as $k) {
                        $kId = $k->id;
                        $kName = htmlspecialchars($k->name->value, ENT_QUOTES, 'UTF-8');
                        $publicHex = bin2hex($k->publicId->bytes);
                        $publicPrefix = 'fvk_' . substr($publicHex, 0, 14) . '...';
                        $kStatus = $k->status->value;
                        $kStatusClass = $kStatus === 'active' ? 'status-active' : 'status-disabled';
                        $kCreated = $k->createdAt->format('Y-m-d H:i');

                        $actionBtn = '';
                        if ($k->status === ApiKeyStatus::ACTIVE) {
                            $revokeUrl = $this->url("/admin/keys/{$kId}/revoke");
                            $actionBtn = "
                            <form method=\"POST\" action=\"{$revokeUrl}\" style=\"display:inline;\" onsubmit=\"return confirm('Revoke API key \"{$kName}\" immediately? Client applications using this key will be disconnected.');\">
                                <input type=\"hidden\" name=\"csrf_token\" value=\"{$csrfToken}\">
                                <button type=\"submit\" class=\"btn-revoke\">Revoke</button>
                            </form>";
                        } else {
                            $revokedAtStr = $k->revokedAt ? $k->revokedAt->format('Y-m-d H:i') : '';
                            $actionBtn = "<span class=\"revoked-tag\">Revoked {$revokedAtStr}</span>";
                        }

                        $keysRowsHtml .= "
                        <tr>
                            <td><span class=\"key-id\">#{$kId}</span></td>
                            <td><strong class=\"key-name\">{$kName}</strong></td>
                            <td><code class=\"key-prefix\">{$publicPrefix}</code></td>
                            <td><span class=\"badge {$kStatusClass}\"><span class=\"dot\"></span>{$kStatus}</span></td>
                            <td class=\"key-date\">{$kCreated}</td>
                            <td class=\"text-right\">{$actionBtn}</td>
                        </tr>";
                    }
                }

                $statusAction = $this->url("/admin/projects/{$pId}/status");
                $projectsHtml .= "
                <div class=\"project-card\" id=\"project-{$pId}\">
                    <div class=\"pc-header\">
                        <div class=\"pc-title-area\">
                            <div class=\"project-id-badge\">#P-{$pId}</div>
                            <div>
                                <h3 class=\"project-name\">{$pName}</h3>
                                <span class=\"project-created\">Registered {$p->createdAt->format('Y-m-d H:i')}</span>
                            </div>
                        </div>
                        <div class=\"pc-status-area\">
                            <span class=\"badge {$statusClass}\">
                                <span class=\"dot\"></span>
                                {$statusVal}
                            </span>
                            
                            <!-- Status Switcher Dropdown Form -->
                            <form method=\"POST\" action=\"{$statusAction}\" class=\"status-form\">
                                <input type=\"hidden\" name=\"csrf_token\" value=\"{$csrfToken}\">
                                <select name=\"status\" onchange=\"this.form.submit()\" class=\"select-status\" title=\"Change project status\">
                                    <option value=\"active\" " . ($statusVal === 'active' ? 'selected' : '') . ">Active</option>
                                    <option value=\"restricted\" " . ($statusVal === 'restricted' ? 'selected' : '') . ">Restricted</option>
                                    <option value=\"disabled\" " . ($statusVal === 'disabled' ? 'selected' : '') . ">Disabled</option>
                                </select>
                            </form>
                        </div>
                    </div>

                    <!-- Usage Meters -->
                    <div class=\"pc-metrics\">
                        <div class=\"metric-box\">
                            <div class=\"metric-label\">
                                <span>Storage Consumption</span>
                                <span class=\"metric-val-mono\">{$usedStorageStr} / {$maxStorageStr} ({$storagePercent}%)</span>
                            </div>
                            <div class=\"progress-bar\">
                                <div class=\"progress-fill\" style=\"width: {$storagePercent}%\"></div>
                            </div>
                        </div>

                        <div class=\"metric-row\">
                            <div class=\"metric-pill\">
                                <span class=\"mp-label\">Active Objects</span>
                                <span class=\"mp-val\">{$fileCountStr} <small>/ {$maxFilesStr}</small></span>
                            </div>
                            <div class=\"metric-pill\">
                                <span class=\"mp-label\">Max Payload Cap</span>
                                <span class=\"mp-val\">{$maxFileSizeStr}</span>
                            </div>
                            <div class=\"metric-pill\">
                                <span class=\"mp-label\">Authorized Keys</span>
                                <span class=\"mp-val\">{$keysCount}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions Bar -->
                    <div class=\"pc-actions\">
                        <button class=\"btn-ghost\" onclick=\"toggleKeysSection({$pId})\">
                            <svg width=\"15\" height=\"15\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z\"/></svg>
                            <span>Manage API Keys ({$keysCount})</span>
                        </button>

                        <button class=\"btn-ghost\" onclick=\"openEditQuotaModal({$pId}, '{$pName}', '{$maxStorageStr}', '{$maxFileSizeStr}', {$quota->maxFileCount})\">
                            <svg width=\"15\" height=\"15\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4\"/></svg>
                            <span>Edit Quota Limits</span>
                        </button>
                    </div>

                    <!-- Collapsible Keys Panel -->
                    <div class=\"keys-panel\" id=\"keys-panel-{$pId}\" style=\"display: none;\">
                        <div class=\"kp-header\">
                            <h4>API Keys for {$pName}</h4>
                            <button class=\"btn-subtle\" onclick=\"openCreateKeyModal({$pId}, '{$pName}')\">
                                <svg width=\"13\" height=\"13\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2.5\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 4v16m8-8H4\"/></svg>
                                <span>Generate New Key</span>
                            </button>
                        </div>

                        <div class=\"table-responsive\">
                            <table class=\"keys-table\">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Public ID</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class=\"text-right\">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {$keysRowsHtml}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>";
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileVault — Control Plane Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #090b0e;
            --surface: #101319;
            --surface-elevated: #151921;
            --surface-hover: #1b202a;
            --border: #1e2430;
            --border-subtle: #161b24;
            --border-hover: #2b3345;
            --border-focus: #3b82f6;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --emerald: #10b981;
            --emerald-bg: #064e3b;
            --emerald-border: #047857;
            --amber: #f59e0b;
            --amber-bg: #451a03;
            --amber-border: #b45309;
            --rose: #ef4444;
            --rose-bg: #450a0a;
            --rose-border: #991b1b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', 'SF Mono', Consolas, monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-sans);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 80px;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Top Navigation */
        header {
            background: #0d1016;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-inner {
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 32px;
            height: 32px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-icon svg { width: 18px; height: 18px; stroke: #60a5fa; }

        .brand-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-info h1 {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--text);
        }

        .brand-env {
            font-family: var(--font-mono);
            font-size: 10.5px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 2px 7px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-muted);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .node-tag {
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-dim);
            padding: 4px 10px;
            background: var(--surface);
            border: 1px solid var(--border-subtle);
            border-radius: 4px;
        }

        .node-tag .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--emerald);
        }

        .user-tag {
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-muted);
            padding: 4px 10px;
            background: var(--surface);
            border: 1px solid var(--border-subtle);
            border-radius: 4px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary);
            border: 1px solid var(--primary-hover);
            border-radius: 6px;
            padding: 7px 14px;
            color: white;
            font-size: 13px;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-logout {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 12.5px;
            font-family: var(--font-sans);
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid transparent;
            transition: all 0.15s;
        }

        .btn-logout:hover {
            color: var(--rose);
            background: var(--rose-bg);
            border-color: var(--rose-border);
        }

        /* Flash banners */
        .banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13.5px;
            margin: 20px 0 0;
        }

        .banner svg { width: 16px; height: 16px; flex-shrink: 0; }
        .banner-success { background: var(--emerald-bg); border: 1px solid var(--emerald-border); color: #a7f3d0; }
        .banner-error { background: var(--rose-bg); border: 1px solid var(--rose-border); color: #fca5a5; }

        /* Key Generated Banner */
        .key-generated-banner {
            margin-top: 20px;
            background: #0d1526;
            border: 1px solid #1d4ed8;
            border-radius: 8px;
            padding: 18px 20px;
        }

        .kgb-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .kgb-icon {
            width: 32px;
            height: 32px;
            background: var(--surface-elevated);
            border: 1px solid #1d4ed8;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .kgb-icon svg { width: 16px; height: 16px; stroke: #60a5fa; }

        .kgb-header h3 { font-size: 14px; font-weight: 600; color: #f8fafc; }
        .kgb-header p { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }

        .kgb-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #080c14;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 14px;
            gap: 14px;
        }

        .kgb-box code {
            font-family: var(--font-mono);
            font-size: 13.5px;
            color: #38bdf8;
            word-break: break-all;
            user-select: all;
        }

        .btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            color: #f8fafc;
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }

        .btn-copy:hover {
            background: var(--surface-hover);
            border-color: var(--border-hover);
        }

        /* Metrics Bar */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin: 24px 0;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px 20px;
        }

        .stat-label {
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .stat-value {
            font-family: var(--font-mono);
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--text);
        }

        .stat-sub {
            font-size: 11.5px;
            color: var(--text-dim);
            margin-top: 4px;
        }

        /* Section Heading */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 32px 0 16px;
        }

        .section-header h2 {
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .count-badge {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 2px 7px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 4px;
            color: var(--text-muted);
        }

        /* Project Cards */
        .projects-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .project-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 22px 24px;
            transition: border-color 0.15s;
        }

        .project-card:hover {
            border-color: var(--border-hover);
        }

        .pc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .pc-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .project-id-badge {
            font-family: var(--font-mono);
            font-size: 11.5px;
            font-weight: 600;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            padding: 3px 8px;
            border-radius: 4px;
            color: #60a5fa;
        }

        .project-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .project-created {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-dim);
            display: block;
            margin-top: 2px;
        }

        .pc-status-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 4px;
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-active { background: var(--emerald-bg); color: #a7f3d0; border: 1px solid var(--emerald-border); }
        .status-active .dot { background: #34d399; }

        .status-restricted { background: var(--amber-bg); color: #fde68a; border: 1px solid var(--amber-border); }
        .status-restricted .dot { background: #fbbf24; }

        .status-disabled { background: var(--rose-bg); color: #fca5a5; border: 1px solid var(--rose-border); }
        .status-disabled .dot { background: #f87171; }

        .select-status {
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-family: var(--font-sans);
            cursor: pointer;
            outline: none;
            transition: border-color 0.15s;
        }

        .select-status:focus { border-color: var(--border-focus); }

        /* Metrics */
        .pc-metrics {
            margin-bottom: 16px;
        }

        .metric-box {
            margin-bottom: 14px;
        }

        .metric-label {
            display: flex;
            justify-content: space-between;
            font-size: 12.5px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .metric-val-mono {
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text);
        }

        .progress-bar {
            height: 6px;
            background: #161b24;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .metric-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .metric-pill {
            background: var(--surface-elevated);
            border: 1px solid var(--border-subtle);
            border-radius: 6px;
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .mp-label {
            font-family: var(--font-mono);
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-dim);
        }

        .mp-val {
            font-family: var(--font-mono);
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }

        .mp-val small {
            font-size: 11px;
            color: var(--text-dim);
            font-weight: normal;
        }

        /* Actions */
        .pc-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 14px;
            border-top: 1px solid var(--border-subtle);
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 6px 12px;
            color: var(--text);
            font-size: 12.5px;
            font-weight: 500;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-ghost:hover {
            background: var(--surface-hover);
            border-color: var(--border-hover);
        }

        .btn-ghost svg { color: var(--text-muted); }

        /* Keys panel */
        .keys-panel {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--border-subtle);
        }

        .kp-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .kp-header h4 {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }

        .btn-subtle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            color: #60a5fa;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-subtle:hover {
            background: var(--surface-hover);
            border-color: var(--border-hover);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .keys-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        .keys-table th {
            text-align: left;
            color: var(--text-dim);
            font-family: var(--font-mono);
            font-size: 10.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
        }

        .keys-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: middle;
        }

        .key-id {
            font-family: var(--font-mono);
            color: var(--text-dim);
            font-size: 11.5px;
        }

        .key-name {
            color: var(--text);
            font-weight: 500;
        }

        .key-prefix {
            font-family: var(--font-mono);
            font-size: 12px;
            background: #080c14;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid var(--border-subtle);
            color: #38bdf8;
        }

        .key-date {
            font-family: var(--font-mono);
            font-size: 11.5px;
            color: var(--text-dim);
        }

        .revoked-tag {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--rose);
        }

        .btn-revoke {
            background: var(--rose-bg);
            border: 1px solid var(--rose-border);
            color: #fca5a5;
            border-radius: 4px;
            padding: 3px 8px;
            font-size: 11.5px;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-revoke:hover {
            background: #7f1d1d;
        }

        .empty-keys {
            text-align: center;
            color: var(--text-dim);
            padding: 20px !important;
            font-size: 12.5px;
        }

        .text-right { text-align: right; }

        /* Modals */
        .modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal.active { display: flex; }

        .modal-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            width: 100%;
            max-width: 460px;
            padding: 28px;
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.6);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-dim);
            font-size: 20px;
            cursor: pointer;
            line-height: 1;
        }

        .modal-close:hover { color: var(--text); }

        .form-row {
            margin-bottom: 16px;
        }

        .form-row label {
            display: block;
            font-family: var(--font-mono);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .form-row input, .form-row select {
            width: 100%;
            background: var(--surface-elevated);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 9px 12px;
            color: var(--text);
            font-size: 13px;
            font-family: var(--font-mono);
            outline: none;
            transition: border-color 0.15s;
        }

        .form-row input:focus, .form-row select:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 1px var(--border-focus);
        }

        .form-hint {
            font-size: 11px;
            color: var(--text-dim);
            margin-top: 4px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        .btn-cancel {
            background: none;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px 14px;
            color: var(--text-muted);
            font-size: 13px;
            font-family: var(--font-sans);
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-cancel:hover { color: var(--text); border-color: var(--border-hover); }

        .empty-state {
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 48px 24px;
            text-align: center;
        }

        .empty-state svg { width: 40px; height: 40px; stroke: var(--text-dim); margin-bottom: 12px; }
        .empty-state h3 { font-size: 15px; margin-bottom: 4px; color: var(--text); }
        .empty-state p { font-size: 13px; color: var(--text-dim); }
    </style>
</head>
<body>

<!-- Header -->
<header>
    <div class="container nav-inner">
        <div class="brand-area">
            <div class="brand-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                </svg>
            </div>
            <div class="brand-info">
                <h1>FileVault</h1>
                <span class="brand-env">CONTROL PLANE v1.0</span>
            </div>
        </div>

        <div class="nav-actions">
            <div class="node-tag">
                <span class="dot"></span>
                <span>NODE: ONLINE</span>
            </div>
            <div class="user-tag">
                <span>USER: <strong>{$adminUser}</strong></span>
            </div>
            <button class="btn-primary" onclick="openModal('createProjectModal')">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span>New Project</span>
            </button>
            <a href="{$logoutUrl}" class="btn-logout">Logout</a>
        </div>
    </div>
</header>

<div class="container">
    {$flashHtml}
    {$newKeyBanner}

    <!-- Top Metrics Overview -->
    <div class="metrics-grid">
        <div class="stat-card">
            <div class="stat-label">Active Projects</div>
            <div class="stat-value">{$totalProjectsCount}</div>
            <div class="stat-sub">Configured vaults</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Storage Used</div>
            <div class="stat-value">{$totalStorageFormatted}</div>
            <div class="stat-sub">Across all vaults</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Stored Objects</div>
            <div class="stat-value">{$totalFilesCountFormatted}</div>
            <div class="stat-sub">Active logical files</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active API Keys</div>
            <div class="stat-value">{$totalKeysCount}</div>
            <div class="stat-sub">Issued credentials</div>
        </div>
    </div>

    <!-- Projects Section -->
    <div class="section-header">
        <h2>
            <span>Projects Directory</span>
            <span class="count-badge">{$totalProjectsCount}</span>
        </h2>
        <button class="btn-primary" onclick="openModal('createProjectModal')">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            <span>Create Project</span>
        </button>
    </div>

    <div class="projects-list">
        {$projectsHtml}
    </div>
</div>

<!-- Modal: Create Project -->
<div class="modal" id="createProjectModal" onclick="closeOnBackdrop(event, this)">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Create Project</h3>
            <button class="modal-close" onclick="closeModal('createProjectModal')">&times;</button>
        </div>
        <form method="POST" action="{$createProjectAction}">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">

            <div class="form-row">
                <label for="new_proj_name">Project Identifier</label>
                <input type="text" id="new_proj_name" name="name" required placeholder="e.g. mobile-app-production">
                <div class="form-hint">Unique project name and storage partition.</div>
            </div>

            <div class="form-row">
                <label for="new_proj_storage">Storage Quota</label>
                <input type="text" id="new_proj_storage" name="max_storage" value="1G" placeholder="e.g. 500M, 1G, 10G">
                <div class="form-hint">Cumulative bytes allowed (0 for unlimited).</div>
            </div>

            <div class="form-row">
                <label for="new_proj_file_size">Max Single Payload</label>
                <input type="text" id="new_proj_file_size" name="max_file_size" value="50M" placeholder="e.g. 20M, 100M">
                <div class="form-hint">Maximum upload size per single file.</div>
            </div>

            <div class="form-row">
                <label for="new_proj_files">Max Object Count</label>
                <input type="number" id="new_proj_files" name="max_file_count" value="1000" min="0" placeholder="1000">
                <div class="form-hint">Maximum active files allowed (0 for unlimited).</div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('createProjectModal')">Cancel</button>
                <button type="submit" class="btn-primary">Create Project</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Quota -->
<div class="modal" id="editQuotaModal" onclick="closeOnBackdrop(event, this)">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Edit Quota: <span id="eq_project_title"></span></h3>
            <button class="modal-close" onclick="closeModal('editQuotaModal')">&times;</button>
        </div>
        <form id="editQuotaForm" method="POST" action="">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">

            <div class="form-row">
                <label for="eq_storage">Max Storage</label>
                <input type="text" id="eq_storage" name="max_storage" required placeholder="e.g. 1G, 500M">
            </div>

            <div class="form-row">
                <label for="eq_file_size">Max Single File Size</label>
                <input type="text" id="eq_file_size" name="max_file_size" required placeholder="e.g. 50M">
            </div>

            <div class="form-row">
                <label for="eq_files">Max Object Count</label>
                <input type="number" id="eq_files" name="max_file_count" required min="0">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editQuotaModal')">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Generate API Key -->
<div class="modal" id="createKeyModal" onclick="closeOnBackdrop(event, this)">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Generate API Key</h3>
            <button class="modal-close" onclick="closeModal('createKeyModal')">&times;</button>
        </div>
        <form id="createKeyForm" method="POST" action="">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">

            <div class="form-row">
                <label>Target Project</label>
                <input type="text" id="ck_project_name" disabled style="opacity: 0.6;">
            </div>

            <div class="form-row">
                <label for="ck_key_name">Key Description / Name</label>
                <input type="text" id="ck_key_name" name="name" required placeholder="e.g. Backend Production Worker">
                <div class="form-hint">Used to identify the consuming application or service.</div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('createKeyModal')">Cancel</button>
                <button type="submit" class="btn-primary">Generate Key</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('active');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function closeOnBackdrop(e, modal) {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    }

    function toggleKeysSection(projectId) {
        const panel = document.getElementById('keys-panel-' + projectId);
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }

    function openEditQuotaModal(projectId, name, maxStorage, maxFileSize, maxFileCount) {
        document.getElementById('eq_project_title').textContent = name;
        document.getElementById('eq_storage').value = maxStorage;
        document.getElementById('eq_file_size').value = maxFileSize;
        document.getElementById('eq_files').value = maxFileCount;
        document.getElementById('editQuotaForm').action = '{$projectApiBase}' + projectId + '/quota';
        openModal('editQuotaModal');
    }

    function openCreateKeyModal(projectId, projectName) {
        document.getElementById('ck_project_name').value = projectName + ' (ID #' + projectId + ')';
        document.getElementById('ck_key_name').value = 'Production Key (' + new Date().toISOString().slice(0, 10) + ')';
        document.getElementById('createKeyForm').action = '{$projectApiBase}' + projectId + '/keys';
        openModal('createKeyModal');
    }

    function copyText(elementId, btn) {
        const text = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(text).then(() => {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span>Copied!</span>';
            setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
        });
    }
</script>

</body>
</html>
HTML;
    }
}
