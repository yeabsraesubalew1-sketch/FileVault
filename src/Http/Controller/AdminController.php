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

    // --------------------------------------------------------------------------
    // Views Rendering
    // --------------------------------------------------------------------------

    private function renderLoginHtml(?string $error, string $csrfToken): string
    {
        $errorAlert = $error ? '
        <div class="alert alert-error">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</span>
        </div>' : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileVault — Admin Control Plane</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #080c14;
            --bg-surface: #0f172a;
            --bg-card: rgba(19, 29, 53, 0.75);
            --border: rgba(255, 255, 255, 0.08);
            --border-focus: rgba(99, 102, 241, 0.5);
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.35);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --rose: #f43f5e;
            --rose-bg: rgba(244, 63, 94, 0.12);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-image: 
                radial-gradient(circle at 50% 20%, rgba(99, 102, 241, 0.12) 0%, transparent 60%),
                radial-gradient(circle at 80% 80%, rgba(6, 182, 212, 0.08) 0%, transparent 50%);
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 44px 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-badge {
            width: 60px;
            height: 60px;
            margin: 0 auto 18px;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px var(--primary-glow);
        }

        .brand-badge svg {
            width: 32px;
            height: 32px;
            stroke: white;
        }

        .brand-header h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .brand-header p {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 6px;
        }

        .alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13.5px;
            margin-bottom: 24px;
            background: var(--rose-bg);
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #fecdd3;
        }

        .alert svg { width: 18px; height: 18px; flex-shrink: 0; }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 13px 16px;
            color: white;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s ease;
        }

        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: none;
            border-radius: 12px;
            padding: 14px;
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            box-shadow: 0 10px 20px -5px var(--primary-glow);
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 25px -4px var(--primary-glow);
        }

        .footer-note {
            text-align: center;
            margin-top: 28px;
            font-size: 12px;
            color: rgba(148, 163, 184, 0.6);
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand-header">
        <div class="brand-badge">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>
        <h1>FileVault Admin</h1>
        <p>Control Plane & Key Management</p>
    </div>

    {$errorAlert}

    <form method="POST" action="/admin/login">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">

        <div class="form-group">
            <label for="username">Administrator Username</label>
            <input type="text" id="username" name="username" required autofocus placeholder="admin" autocomplete="username">
        </div>

        <div class="form-group">
            <label for="password">Master Password</label>
            <input type="password" id="password" name="password" required placeholder="••••••••" autocomplete="current-password">
        </div>

        <button type="submit" class="btn-submit">Sign In to Dashboard</button>
    </form>

    <div class="footer-note">
        Protected Control Plane &bull; FileVault v1.0
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

        $flashHtml = '';
        if ($data['success']) {
            $msg = htmlspecialchars($data['success'], ENT_QUOTES, 'UTF-8');
            $flashHtml .= "
            <div class=\"banner banner-success\">
                <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z\"/></svg>
                <span>{$msg}</span>
            </div>";
        }
        if ($data['error']) {
            $msg = htmlspecialchars($data['error'], ENT_QUOTES, 'UTF-8');
            $flashHtml .= "
            <div class=\"banner banner-error\">
                <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z\"/></svg>
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
                        <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z\"/></svg>
                    </div>
                    <div>
                        <h3>New API Key Created: {$keyName} (ID: #{$keyId})</h3>
                        <p>Project: <strong>{$projName}</strong> &bull; Save this credential immediately. It will not be shown again.</p>
                    </div>
                </div>
                <div class=\"kgb-box\">
                    <code id=\"newGeneratedKey\">{$keyPlaintext}</code>
                    <button class=\"btn-copy\" onclick=\"copyText('newGeneratedKey', this)\">
                        <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3\"/></svg>
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
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
                <h3>No projects yet</h3>
                <p>Create your first project above to begin issuing API keys and uploading files.</p>
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
                            $actionBtn = "
                            <form method=\"POST\" action=\"/admin/keys/{$kId}/revoke\" style=\"display:inline;\" onsubmit=\"return confirm('Revoke API key \"{$kName}\" immediately? Client applications using this key will be disconnected.');\">
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
                            <td><strong>{$kName}</strong></td>
                            <td><code class=\"key-prefix\">{$publicPrefix}</code></td>
                            <td><span class=\"badge {$kStatusClass}\">{$kStatus}</span></td>
                            <td>{$kCreated}</td>
                            <td class=\"text-right\">{$actionBtn}</td>
                        </tr>";
                    }
                }

                $projectsHtml .= "
                <div class=\"project-card\" id=\"project-{$pId}\">
                    <div class=\"pc-header\">
                        <div class=\"pc-title-area\">
                            <div class=\"project-id-badge\">#{$pId}</div>
                            <div>
                                <h3 class=\"project-name\">{$pName}</h3>
                                <span class=\"project-created\">Created {$p->createdAt->format('M d, Y')}</span>
                            </div>
                        </div>
                        <div class=\"pc-status-area\">
                            <span class=\"badge {$statusClass}\">
                                <span class=\"dot\"></span>
                                {$statusVal}
                            </span>
                            
                            <!-- Status Switcher Dropdown Form -->
                            <form method=\"POST\" action=\"/admin/projects/{$pId}/status\" class=\"status-form\">
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
                                <span>Storage Usage</span>
                                <span>{$usedStorageStr} / {$maxStorageStr}</span>
                            </div>
                            <div class=\"progress-bar\">
                                <div class=\"progress-fill\" style=\"width: {$storagePercent}%\"></div>
                            </div>
                        </div>

                        <div class=\"metric-row\">
                            <div class=\"metric-pill\">
                                <span class=\"mp-label\">Active Files</span>
                                <span class=\"mp-val\">{$fileCountStr} <small>/ {$maxFilesStr}</small></span>
                            </div>
                            <div class=\"metric-pill\">
                                <span class=\"mp-label\">Max Upload</span>
                                <span class=\"mp-val\">{$maxFileSizeStr}</span>
                            </div>
                            <div class=\"metric-pill\">
                                <span class=\"mp-label\">API Keys</span>
                                <span class=\"mp-val\">{$keysCount}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions Bar -->
                    <div class=\"pc-actions\">
                        <button class=\"btn-ghost\" onclick=\"toggleKeysSection({$pId})\">
                            <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z\"/></svg>
                            <span>Manage API Keys ({$keysCount})</span>
                        </button>

                        <button class=\"btn-ghost\" onclick=\"openEditQuotaModal({$pId}, '{$pName}', '{$maxStorageStr}', '{$maxFileSizeStr}', {$quota->maxFileCount})\">
                            <svg fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z\"/><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M15 12a3 3 0 11-6 0 3 3 0 016 0z\"/></svg>
                            <span>Edit Quota</span>
                        </button>
                    </div>

                    <!-- Collapsible Keys Panel -->
                    <div class=\"keys-panel\" id=\"keys-panel-{$pId}\" style=\"display: none;\">
                        <div class=\"kp-header\">
                            <h4>API Keys for {$pName}</h4>
                            <button class=\"btn-subtle\" onclick=\"openCreateKeyModal({$pId}, '{$pName}')\">
                                <svg width=\"14\" height=\"14\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\" stroke-width=\"2\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"M12 4v16m8-8H4\"/></svg>
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
    <title>FileVault — Administrative Control Plane</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #070a12;
            --bg-surface: #0f172a;
            --bg-card: rgba(20, 29, 51, 0.7);
            --bg-card-hover: rgba(26, 38, 66, 0.85);
            --border: rgba(255, 255, 255, 0.08);
            --border-subtle: rgba(255, 255, 255, 0.04);
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --cyan: #06b6d4;
            --emerald: #10b981;
            --emerald-bg: rgba(16, 185, 129, 0.12);
            --amber: #f59e0b;
            --amber-bg: rgba(245, 158, 11, 0.12);
            --rose: #f43f5e;
            --rose-bg: rgba(244, 63, 94, 0.12);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            padding-bottom: 80px;
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(99, 102, 241, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 90%, rgba(6, 182, 212, 0.06) 0%, transparent 40%);
        }

        .container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Top Navigation */
        header {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-inner {
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 16px -4px var(--primary-glow);
        }

        .brand-icon svg { width: 22px; height: 22px; stroke: white; }

        .brand-info h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .brand-info p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .user-tag {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: 20px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .user-tag .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--emerald);
            box-shadow: 0 0 8px var(--emerald);
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: none;
            border-radius: 10px;
            padding: 9px 18px;
            color: white;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 8px 16px -4px var(--primary-glow);
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 20px -2px var(--primary-glow);
        }

        .btn-logout {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .btn-logout:hover {
            color: var(--rose);
            background: var(--rose-bg);
        }

        /* Flash banners */
        .banner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 14px;
            margin: 24px 0 0;
            animation: fadeIn 0.3s ease;
        }

        .banner svg { width: 20px; height: 20px; flex-shrink: 0; }
        .banner-success { background: var(--emerald-bg); border: 1px solid rgba(16, 185, 129, 0.3); color: #a7f3d0; }
        .banner-error { background: var(--rose-bg); border: 1px solid rgba(244, 63, 94, 0.3); color: #fecdd3; }

        /* Key Generated Banner */
        .key-generated-banner {
            margin-top: 24px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(6, 182, 212, 0.15));
            border: 1px solid rgba(99, 102, 241, 0.4);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 15px 30px -10px var(--primary-glow);
            animation: pulseGlow 2s infinite alternate ease-in-out;
        }

        @keyframes pulseGlow {
            from { border-color: rgba(99, 102, 241, 0.4); }
            to { border-color: rgba(6, 182, 212, 0.6); }
        }

        .kgb-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .kgb-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #4f46e5, #06b6d4);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .kgb-icon svg { width: 22px; height: 22px; stroke: white; }

        .kgb-header h3 { font-size: 16px; font-weight: 700; }
        .kgb-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

        .kgb-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(10, 15, 26, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 12px 18px;
            gap: 16px;
        }

        .kgb-box code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            color: #38bdf8;
            word-break: break-all;
            user-select: all;
        }

        .btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(99, 102, 241, 0.2);
            border: 1px solid var(--primary);
            color: white;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }

        .btn-copy:hover {
            background: var(--primary);
        }

        .btn-copy svg { width: 14px; height: 14px; }

        /* Metrics Bar */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin: 28px 0;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 24px;
            backdrop-filter: blur(12px);
        }

        .stat-label {
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }

        .stat-value {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .stat-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Section Heading */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 36px 0 20px;
        }

        .section-header h2 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        /* Project Cards */
        .projects-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .project-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 26px 28px;
            backdrop-filter: blur(14px);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .project-card:hover {
            border-color: rgba(99, 102, 241, 0.3);
            box-shadow: 0 12px 28px -8px rgba(0, 0, 0, 0.4);
        }

        .pc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }

        .pc-title-area {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .project-id-badge {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid var(--border);
            padding: 4px 10px;
            border-radius: 8px;
            color: var(--text-muted);
        }

        .project-name {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 19px;
            font-weight: 700;
        }

        .project-created {
            font-size: 12px;
            color: var(--text-muted);
        }

        .pc-status-area {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-active { background: var(--emerald-bg); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .status-active .dot { background: #34d399; box-shadow: 0 0 6px #34d399; }

        .status-restricted { background: var(--amber-bg); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-restricted .dot { background: #fbbf24; box-shadow: 0 0 6px #fbbf24; }

        .status-disabled { background: var(--rose-bg); color: #fb7185; border: 1px solid rgba(244, 63, 94, 0.3); }
        .status-disabled .dot { background: #fb7185; box-shadow: 0 0 6px #fb7185; }

        .select-status {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 12.5px;
            font-family: inherit;
            cursor: pointer;
            outline: none;
        }

        .select-status:focus { border-color: var(--primary); }

        /* Metrics */
        .pc-metrics {
            margin-bottom: 20px;
        }

        .metric-box {
            margin-bottom: 16px;
        }

        .metric-label {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .progress-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4f46e5, #06b6d4);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .metric-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .metric-pill {
            background: rgba(255, 255, 255, 0.025);
            border: 1px solid var(--border-subtle);
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .mp-label { font-size: 11.5px; color: var(--text-muted); }
        .mp-val { font-size: 14px; font-weight: 600; }
        .mp-val small { font-size: 12px; color: var(--text-muted); font-weight: normal; }

        /* Actions */
        .pc-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid var(--border-subtle);
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 14px;
            color: var(--text-main);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.16);
        }

        .btn-ghost svg { width: 16px; height: 16px; color: var(--text-muted); }

        /* Keys panel */
        .keys-panel {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed var(--border);
            animation: fadeIn 0.2s ease-out;
        }

        .kp-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .kp-header h4 {
            font-size: 14px;
            font-weight: 600;
        }

        .btn-subtle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(99, 102, 241, 0.12);
            border: 1px solid rgba(99, 102, 241, 0.3);
            color: #c7d2fe;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-subtle:hover {
            background: rgba(99, 102, 241, 0.25);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .keys-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .keys-table th {
            text-align: left;
            color: var(--text-muted);
            font-size: 11.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
        }

        .keys-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: middle;
        }

        .key-id {
            font-family: 'JetBrains Mono', monospace;
            color: var(--text-muted);
            font-size: 12px;
        }

        .key-prefix {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            background: rgba(0, 0, 0, 0.3);
            padding: 2px 6px;
            border-radius: 4px;
            color: #7dd3fc;
        }

        .revoked-tag {
            font-size: 11.5px;
            color: var(--rose);
        }

        .btn-revoke {
            background: var(--rose-bg);
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #fecdd3;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-revoke:hover {
            background: rgba(244, 63, 94, 0.3);
        }

        .empty-keys {
            text-align: center;
            color: var(--text-muted);
            padding: 24px !important;
            font-style: italic;
        }

        .text-right { text-align: right; }

        /* Modals */
        .modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal.active { display: flex; }

        .modal-card {
            background: #0f172a;
            border: 1px solid var(--border);
            border-radius: 20px;
            width: 100%;
            max-width: 480px;
            padding: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            animation: fadeIn 0.25s ease-out;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .modal-header h3 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 700;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
            line-height: 1;
        }

        .modal-close:hover { color: white; }

        .form-row {
            margin-bottom: 18px;
        }

        .form-row label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .form-row input, .form-row select {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 11px 14px;
            color: white;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-row input:focus, .form-row select:focus {
            border-color: var(--primary);
        }

        .form-hint {
            font-size: 11.5px;
            color: rgba(148, 163, 184, 0.7);
            margin-top: 4px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 28px;
        }

        .btn-cancel {
            background: none;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 18px;
            color: var(--text-muted);
            font-size: 13.5px;
            cursor: pointer;
        }

        .btn-cancel:hover { color: white; border-color: rgba(255, 255, 255, 0.2); }

        .empty-state {
            background: var(--bg-card);
            border: 1px dashed var(--border);
            border-radius: 20px;
            padding: 60px 24px;
            text-align: center;
        }

        .empty-state svg { width: 48px; height: 48px; stroke: var(--text-muted); margin-bottom: 16px; }
        .empty-state h3 { font-size: 18px; margin-bottom: 6px; }
        .empty-state p { font-size: 13.5px; color: var(--text-muted); }
    </style>
</head>
<body>

<!-- Header -->
<header>
    <div class="container nav-inner">
        <div class="brand-area">
            <div class="brand-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <div class="brand-info">
                <h1>FileVault Admin</h1>
                <p>Management Control Plane</p>
            </div>
        </div>

        <div class="nav-actions">
            <div class="user-tag">
                <span class="dot"></span>
                <span>Logged in as <strong>{$adminUser}</strong></span>
            </div>
            <button class="btn-primary" onclick="openModal('createProjectModal')">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span>New Project</span>
            </button>
            <a href="/admin/logout" class="btn-logout">Logout</a>
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
            <div class="stat-sub">Across all projects</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Stored Files</div>
            <div class="stat-value">{$totalFilesCountFormatted}</div>
            <div class="stat-sub">Active logical objects</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active API Keys</div>
            <div class="stat-value">{$totalKeysCount}</div>
            <div class="stat-sub">Authorized credentials</div>
        </div>
    </div>

    <!-- Projects Section -->
    <div class="section-header">
        <h2>Registered Projects</h2>
        <button class="btn-primary" onclick="openModal('createProjectModal')">
            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
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
            <h3>Create New Project</h3>
            <button class="modal-close" onclick="closeModal('createProjectModal')">&times;</button>
        </div>
        <form method="POST" action="/admin/projects">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">

            <div class="form-row">
                <label for="new_proj_name">Project Name</label>
                <input type="text" id="new_proj_name" name="name" required placeholder="e.g. mobile-app-production">
                <div class="form-hint">Unique identifier for this project bucket.</div>
            </div>

            <div class="form-row">
                <label for="new_proj_storage">Storage Quota</label>
                <input type="text" id="new_proj_storage" name="max_storage" value="1G" placeholder="e.g. 500M, 1G, 10G">
                <div class="form-hint">Maximum cumulative bytes allowed (0 for unlimited).</div>
            </div>

            <div class="form-row">
                <label for="new_proj_file_size">Max Single File Size</label>
                <input type="text" id="new_proj_file_size" name="max_file_size" value="50M" placeholder="e.g. 20M, 100M">
                <div class="form-hint">Maximum size per individual uploaded file.</div>
            </div>

            <div class="form-row">
                <label for="new_proj_files">Max File Count</label>
                <input type="number" id="new_proj_files" name="max_file_count" value="1000" min="0" placeholder="1000">
                <div class="form-hint">Max logical active files permitted (0 for unlimited).</div>
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
                <label for="eq_file_size">Max File Size</label>
                <input type="text" id="eq_file_size" name="max_file_size" required placeholder="e.g. 50M">
            </div>

            <div class="form-row">
                <label for="eq_files">Max File Count</label>
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
                <input type="text" id="ck_project_name" disabled style="opacity: 0.7;">
            </div>

            <div class="form-row">
                <label for="ck_key_name">Key Description / Name</label>
                <input type="text" id="ck_key_name" name="name" required placeholder="e.g. Backend Production Worker">
                <div class="form-hint">Used to identify where this key is deployed.</div>
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
        document.getElementById('editQuotaForm').action = '/admin/projects/' + projectId + '/quota';
        openModal('editQuotaModal');
    }

    function openCreateKeyModal(projectId, projectName) {
        document.getElementById('ck_project_name').value = projectName + ' (ID #' + projectId + ')';
        document.getElementById('ck_key_name').value = 'Production Key (' + new Date().toISOString().slice(0, 10) + ')';
        document.getElementById('createKeyForm').action = '/admin/projects/' + projectId + '/keys';
        openModal('createKeyModal');
    }

    function copyText(elementId, btn) {
        const text = document.getElementById(elementId).textContent;
        navigator.clipboard.writeText(text).then(() => {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span>Copied!</span>';
            setTimeout(() => { btn.innerHTML = originalHtml; }, 2500);
        });
    }
</script>

</body>
</html>
HTML;
    }
}
