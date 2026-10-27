<?php

declare(strict_types=1);

// 1. Session Management (Never leak keys in client-side code)
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

// 2. Resolve FileVault API URL (Local vs Bluehost/Remote)
$config = [
    'FILEVAULT_API_URL' => '',
];

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $config[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }
}

$rawApiUrl = trim($config['FILEVAULT_API_URL'] ?? '');
$isLocalhostServer = in_array(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0], ['localhost', '127.0.0.1'], true);

if ($rawApiUrl === '' || ($rawApiUrl === 'http://localhost:8000' && !$isLocalhostServer)) {
    // Try reading APP_URL from parent .env
    $parentEnv = dirname(__DIR__) . '/.env';
    if (file_exists($parentEnv)) {
        $parentLines = file($parentEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($parentLines as $pLine) {
            $pLine = trim($pLine);
            if (str_starts_with($pLine, 'APP_URL=')) {
                $rawApiUrl = trim(substr($pLine, 8), " \t\n\r\0\x0B\"'");
                break;
            }
        }
    }

    // Auto-detect from current web server host and script path
    if (($rawApiUrl === '' || $rawApiUrl === 'http://localhost:8000') && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $subpath = preg_replace('#/client(/.*)?$#i', '', $_SERVER['REQUEST_URI'] ?? '');
        $rawApiUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($subpath, '/');
    }
}

$apiUrl = rtrim($rawApiUrl ?: 'http://localhost:8000', '/');

// Active API Key is strictly stored in server session (never exposed to client browser)
$apiKey = $_SESSION['fv_client_key'] ?? '';

// Helper for sending server-to-server HTTP requests to FileVault API
function callFileVault(string $url, string $method = 'GET', ?array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        $headers[] = 'Content-Length: 0';
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'status' => 500,
            'data' => ['error' => ['message' => 'Failed to connect to FileVault: ' . $curlError]],
        ];
    }

    $decoded = json_decode($response, true);
    return [
        'status' => $httpCode,
        'data' => is_array($decoded) ? $decoded : ['raw' => $response],
    ];
}

// 3. Handle internal AJAX actions
$action = $_GET['action'] ?? null;

// Connect with Project API Key
if ($action === 'connect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $submittedKey = trim((string) ($_POST['api_key'] ?? ''));

    if ($submittedKey === '') {
        http_response_code(400);
        echo json_encode(['connected' => false, 'error' => 'API Key cannot be empty.']);
        exit;
    }

    $res = callFileVault($apiUrl . '/api/v1/project', 'GET', [
        'X-API-Key: ' . $submittedKey,
        'Accept: application/json',
    ]);

    if ($res['status'] === 200 && isset($res['data']['project'])) {
        $_SESSION['fv_client_key'] = $submittedKey;
        $_SESSION['fv_client_project'] = $res['data']['project'];
        echo json_encode(['connected' => true, 'project' => $res['data']['project']]);
    } else {
        http_response_code(401);
        $msg = $res['data']['error']['message'] ?? 'Invalid or inactive API Key.';
        echo json_encode(['connected' => false, 'error' => $msg]);
    }
    exit;
}

// Disconnect from Project
if ($action === 'disconnect') {
    unset($_SESSION['fv_client_key'], $_SESSION['fv_client_project']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['connected' => false]);
    } else {
        header('Location: ?');
    }
    exit;
}

// Project Status Check
if ($action === 'api_status') {
    header('Content-Type: application/json; charset=UTF-8');
    if ($apiKey === '') {
        echo json_encode(['connected' => false]);
        exit;
    }

    $res = callFileVault($apiUrl . '/api/v1/project', 'GET', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    if ($res['status'] === 200 && isset($res['data']['project'])) {
        $_SESSION['fv_client_project'] = $res['data']['project'];
        echo json_encode(['connected' => true, 'project' => $res['data']['project']]);
    } else {
        unset($_SESSION['fv_client_key'], $_SESSION['fv_client_project']);
        http_response_code(401);
        echo json_encode(['connected' => false, 'error' => 'Session expired or API key revoked.']);
    }
    exit;
}

// Authenticated Actions Guard
if (in_array($action, ['files', 'trash', 'upload', 'delete', 'restore', 'purge', 'download', 'replace'], true)) {
    if ($apiKey === '') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode(['error' => ['message' => 'Please connect with an API Key to continue.']]);
        exit;
    }
}

// Fetch Active Files
if ($action === 'files') {
    header('Content-Type: application/json; charset=UTF-8');
    $res = callFileVault($apiUrl . '/api/v1/files?limit=100', 'GET', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    if (isset($res['data']['files']) && is_array($res['data']['files'])) {
        foreach ($res['data']['files'] as &$file) {
            if (!empty($file['storage_key'])) {
                $file['url'] = $apiUrl . '/files/' . $file['storage_key'];
            }
        }
    }
    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Fetch Trashed Files
if ($action === 'trash') {
    header('Content-Type: application/json; charset=UTF-8');
    $res = callFileVault($apiUrl . '/api/v1/files/trash?limit=100', 'GET', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    if (isset($res['data']['files']) && is_array($res['data']['files'])) {
        foreach ($res['data']['files'] as &$file) {
            if (!empty($file['storage_key'])) {
                $file['url'] = $apiUrl . '/files/' . $file['storage_key'];
            }
        }
    }
    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Upload File
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Please select a valid file to upload.']]);
        exit;
    }

    $file = $_FILES['file'];
    $visibility = $_POST['visibility'] ?? 'public';
    $customName = trim($_POST['name'] ?? '');

    $postData = [
        'file' => new CURLFile($file['tmp_name'], $file['type'], $file['name']),
        'visibility' => $visibility,
    ];
    if ($customName !== '') {
        $postData['name'] = $customName;
    }

    $res = callFileVault($apiUrl . '/api/v1/files', 'POST', [
        'X-API-Key: ' . $apiKey,
    ], $postData);

    if (isset($res['data']['file']['storage_key'])) {
        $res['data']['file']['url'] = $apiUrl . '/files/' . $res['data']['file']['storage_key'];
    }

    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Replace File Content
if ($action === 'replace' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit($id)) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Invalid file ID.']]);
        exit;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Please select a replacement file.']]);
        exit;
    }

    $file = $_FILES['file'];
    $visibility = $_POST['visibility'] ?? 'public';
    $customName = trim($_POST['name'] ?? '');

    $postData = [
        'file' => new CURLFile($file['tmp_name'], $file['type'], $file['name']),
        'visibility' => $visibility,
    ];
    if ($customName !== '') {
        $postData['name'] = $customName;
    }

    $res = callFileVault($apiUrl . '/api/v1/files/' . $id . '/replace', 'POST', [
        'X-API-Key: ' . $apiKey,
    ], $postData);

    if (isset($res['data']['file']['storage_key'])) {
        $res['data']['file']['url'] = $apiUrl . '/files/' . $res['data']['file']['storage_key'];
    }

    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Soft Delete (Move to Trash)
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit($id)) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Invalid file ID.']]);
        exit;
    }

    $res = callFileVault($apiUrl . '/api/v1/files/' . $id, 'DELETE', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Restore from Trash
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit($id)) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Invalid file ID.']]);
        exit;
    }

    $res = callFileVault($apiUrl . '/api/v1/files/' . $id . '/restore', 'POST', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Permanent Purge
if ($action === 'purge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit($id)) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'Invalid file ID.']]);
        exit;
    }

    $res = callFileVault($apiUrl . '/api/v1/files/' . $id . '/purge', 'DELETE', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    http_response_code($res['status']);
    echo json_encode($res['data']);
    exit;
}

// Download File (Stream through client)
if ($action === 'download') {
    $id = $_GET['id'] ?? null;
    if (!$id || !ctype_digit($id)) {
        http_response_code(400);
        echo 'Invalid file ID';
        exit;
    }

    $downloadUrl = $apiUrl . '/api/v1/files/' . $id . '/download';
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) {
            $len = strlen($headerLine);
            $clean = trim($headerLine);
            if ($clean === '' || str_starts_with(strtolower($clean), 'http/')) {
                return $len;
            }
            $lower = strtolower($clean);
            if (
                str_starts_with($lower, 'content-type:') ||
                str_starts_with($lower, 'content-disposition:') ||
                str_starts_with($lower, 'content-length:') ||
                str_starts_with($lower, 'accept-ranges:') ||
                str_starts_with($lower, 'x-content-type-options:')
            ) {
                header($clean);
            }
            return $len;
        },
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400 && !headers_sent()) {
        http_response_code($status);
        echo 'Failed to stream requested file.';
    }
    exit;
}

$initialProject = $_SESSION['fv_client_project'] ?? null;
$isConnected = ($apiKey !== '' && $initialProject !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileVault | Project Vault</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #070a12;
            --bg-surface: #0e1526;
            --bg-card: rgba(15, 23, 42, 0.75);
            --bg-card-hover: rgba(22, 33, 62, 0.85);
            --border: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(99, 102, 241, 0.35);
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --accent-audio: #a855f7;
            --accent-audio-glow: rgba(168, 85, 247, 0.25);
            --accent-cyan: #06b6d4;
            --emerald: #10b981;
            --emerald-bg: rgba(16, 185, 129, 0.12);
            --rose: #f43f5e;
            --rose-bg: rgba(244, 63, 94, 0.12);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-image: 
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(99, 102, 241, 0.18), transparent 70%),
                radial-gradient(circle at 90% 20%, rgba(168, 85, 247, 0.08), transparent 40%);
            background-attachment: fixed;
        }

        /* Top Navbar */
        header {
            background: rgba(14, 21, 38, 0.85);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 14px 28px;
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
            background: linear-gradient(135deg, #6366f1, #a855f7);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .brand-icon svg { width: 22px; height: 22px; stroke: white; }

        .brand-title {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-badge-tag {
            font-family: 'Inter', sans-serif;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .nav-controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .project-pill {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 6px 14px;
            font-size: 13px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--emerald);
            box-shadow: 0 0 10px var(--emerald);
        }

        .btn-disconnect {
            background: transparent;
            border: 1px solid rgba(244, 63, 94, 0.3);
            color: #fda4af;
            font-size: 12.5px;
            font-weight: 500;
            padding: 7px 14px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .btn-disconnect:hover {
            background: var(--rose-bg);
            border-color: var(--rose);
            color: white;
        }

        /* Container & Main Layout */
        main {
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 28px;
            flex: 1;
        }

        /* Connect Screen (When Not Authenticated) */
        .connect-screen {
            max-width: 500px;
            margin: 60px auto;
            background: var(--bg-card);
            backdrop-filter: blur(24px);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            text-align: center;
        }

        .connect-icon-wrapper {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(168, 85, 247, 0.2));
            border: 1px solid rgba(99, 102, 241, 0.4);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 25px -5px var(--primary-glow);
        }

        .connect-icon-wrapper svg { width: 32px; height: 32px; stroke: #a5b4fc; }

        .connect-screen h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .connect-screen p {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 28px;
        }

        .form-connect {
            display: flex;
            flex-direction: column;
            gap: 16px;
            text-align: left;
        }

        .form-connect label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .input-key {
            width: 100%;
            background: rgba(14, 21, 38, 0.9);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            color: white;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
        }

        .input-key:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .btn-connect-submit {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 10px 20px -5px var(--primary-glow);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-connect-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 25px -4px var(--primary-glow);
        }

        .connect-hint {
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-dim);
            line-height: 1.4;
        }

        /* Quota & Overview Bar */
        .vault-hero {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-widget {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: border-color 0.2s ease;
        }

        .stat-widget:hover { border-color: var(--border-hover); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
        }

        .stat-value {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 700;
            margin: 8px 0;
            color: var(--text-main);
        }

        .progress-bar-container {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #6366f1, #a855f7);
            transition: width 0.4s ease;
        }

        .stat-sub {
            font-size: 12px;
            color: var(--text-dim);
            margin-top: 6px;
        }

        /* Controls & Filter Bar */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }

        .tabs-group {
            display: flex;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 4px;
            gap: 4px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 13.5px;
            font-weight: 500;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn.active {
            background: var(--bg-surface);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .tab-btn .badge-count {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
        }

        .tab-btn.active .badge-count {
            background: var(--primary);
            color: white;
        }

        .search-and-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            justify-content: flex-end;
        }

        .search-box {
            position: relative;
            max-width: 300px;
            width: 100%;
        }

        .search-box svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            stroke: var(--text-dim);
            pointer-events: none;
        }

        .search-box input {
            width: 100%;
            background: rgba(14, 21, 38, 0.8);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 9px 14px 9px 38px;
            color: white;
            font-size: 13.5px;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-box input:focus { border-color: var(--primary); }

        .type-filters {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .filter-chip {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            color: var(--text-muted);
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-chip:hover, .filter-chip.active {
            background: rgba(99, 102, 241, 0.15);
            border-color: rgba(99, 102, 241, 0.4);
            color: #c7d2fe;
        }

        .btn-upload-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 9px 18px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px var(--primary-glow);
            transition: all 0.2s;
        }

        .btn-upload-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px var(--primary-glow);
        }

        /* Files Grid */
        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .file-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: all 0.2s ease;
            position: relative;
        }

        .file-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px -10px rgba(0, 0, 0, 0.6);
        }

        /* Card Previews */
        .card-preview {
            width: 100%;
            height: 180px;
            background: rgba(14, 21, 38, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            border-bottom: 1px solid var(--border);
        }

        .card-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .card-preview img:hover { transform: scale(1.04); }

        /* Audio Card Special Styling */
        .audio-preview-box {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.12), rgba(99, 102, 241, 0.15));
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
            gap: 12px;
        }

        .audio-wave-anim {
            display: flex;
            align-items: center;
            gap: 4px;
            height: 32px;
        }

        .audio-bar {
            width: 4px;
            background: var(--accent-audio);
            border-radius: 2px;
            animation: soundWave 1.2s ease-in-out infinite alternate;
        }

        .audio-bar:nth-child(1) { height: 12px; animation-delay: 0.1s; }
        .audio-bar:nth-child(2) { height: 26px; animation-delay: 0.3s; }
        .audio-bar:nth-child(3) { height: 32px; animation-delay: 0.2s; }
        .audio-bar:nth-child(4) { height: 18px; animation-delay: 0.4s; }
        .audio-bar:nth-child(5) { height: 28px; animation-delay: 0.15s; }
        .audio-bar:nth-child(6) { height: 14px; animation-delay: 0.25s; }

        @keyframes soundWave {
            0% { transform: scaleY(0.4); opacity: 0.6; }
            100% { transform: scaleY(1.1); opacity: 1; }
        }

        .inline-audio-player {
            width: 100%;
            height: 38px;
            border-radius: 20px;
            outline: none;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));
        }

        .generic-file-preview {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            color: var(--text-dim);
        }

        .generic-file-preview svg { width: 44px; height: 44px; stroke: var(--text-muted); }

        .card-body {
            padding: 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .file-name-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .file-title {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--text-main);
            word-break: break-all;
            line-height: 1.4;
        }

        .badge-type {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .badge-audio { background: rgba(168, 85, 247, 0.15); color: #d8b4fe; border: 1px solid rgba(168, 85, 247, 0.3); }
        .badge-image { background: rgba(6, 182, 212, 0.15); color: #67e8f9; border: 1px solid rgba(6, 182, 212, 0.3); }
        .badge-doc { background: rgba(245, 158, 11, 0.15); color: #fde68a; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-trash { background: var(--rose-bg); color: #fda4af; border: 1px solid rgba(244, 63, 94, 0.3); }

        .file-meta-row {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 12px;
            color: var(--text-dim);
            margin-bottom: 16px;
        }

        .card-actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
            border-top: 1px solid var(--border);
            padding-top: 14px;
        }

        .btn-card {
            flex: 1;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 12.5px;
            font-weight: 500;
            padding: 7px 10px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-card:hover {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            border-color: var(--border-hover);
        }

        .btn-card.danger {
            color: #fda4af;
            border-color: rgba(244, 63, 94, 0.3);
        }

        .btn-card.danger:hover {
            background: var(--rose-bg);
            border-color: var(--rose);
            color: white;
        }

        .btn-card.success {
            color: #6ee7b7;
            border-color: rgba(16, 185, 129, 0.3);
        }

        .btn-card.success:hover {
            background: var(--emerald-bg);
            border-color: var(--emerald);
            color: white;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 100;
            padding: 20px;
        }

        .modal.active { display: flex; }

        .modal-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            max-width: 520px;
            width: 100%;
            padding: 30px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .modal-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-dim);
            font-size: 22px;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }

        .modal-close:hover { color: white; }

        /* Upload Dropzone */
        .dropzone {
            border: 2px dashed rgba(99, 102, 241, 0.3);
            border-radius: 14px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            background: rgba(99, 102, 241, 0.04);
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.08);
        }

        .dropzone svg { width: 44px; height: 44px; stroke: #a5b4fc; margin-bottom: 12px; }

        .dropzone-title { font-weight: 600; font-size: 14.5px; margin-bottom: 4px; }
        .dropzone-sub { font-size: 12px; color: var(--text-dim); }

        .form-row {
            margin-bottom: 16px;
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
            background: rgba(14, 21, 38, 0.9);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            color: white;
            font-size: 13.5px;
            outline: none;
        }

        .form-row input:focus, .form-row select:focus {
            border-color: var(--primary);
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 200;
        }

        .toast {
            background: #1e293b;
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 500;
            border: 1px solid var(--border);
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        .toast.error { background: #881337; border-color: #be123c; }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            color: var(--text-dim);
        }

        .empty-state svg { width: 56px; height: 56px; stroke: var(--text-dim); margin-bottom: 16px; }
        .empty-state h4 { font-size: 18px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; }

        @media (max-width: 768px) {
            .nav-container { padding: 12px 18px; }
            main { padding: 20px 16px; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .search-and-actions { flex-direction: column; align-items: stretch; }
            .search-box { max-width: 100%; }
        }
    </style>
</head>
<body>

<header>
    <div class="nav-container">
        <div class="brand-area">
            <div class="brand-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <div class="brand-title">
                FileVault <span class="brand-badge-tag">Explorer</span>
            </div>
        </div>

        <div class="nav-controls" id="navControls" style="<?= $isConnected ? '' : 'display: none;' ?>">
            <div class="project-pill">
                <span class="status-dot"></span>
                <span>Project: <strong id="navProjectName"><?= htmlspecialchars($initialProject['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></span>
            </div>
            <button class="btn-disconnect" onclick="disconnect()">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                <span>Disconnect</span>
            </button>
        </div>
    </div>
</header>

<main>
    <!-- 1. Connect Card (When Not Authenticated) -->
    <div class="connect-screen" id="connectScreen" style="<?= $isConnected ? 'display: none;' : '' ?>">
        <div class="connect-icon-wrapper">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
            </svg>
        </div>
        <h2>Connect to Project Vault</h2>
        <p>Enter an authorized project API key to explore, upload, and stream files securely.</p>

        <form class="form-connect" onsubmit="handleConnect(event)">
            <div>
                <label for="inputApiKey">Project API Key</label>
                <input type="password" id="inputApiKey" class="input-key" placeholder="fvk_xxxxxxxxxxxxxxxxxxxx" autocomplete="off" required autofocus>
            </div>
            <button type="submit" class="btn-connect-submit" id="btnConnect">
                <span>Authenticate & Open Vault</span>
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </button>
        </form>

        <div class="connect-hint">
            API keys are generated inside the FileVault Admin Control Plane (<a href="../admin" style="color: #818cf8; text-decoration: none;">/admin</a>). Your key is verified server-side and never exposed in the browser.
        </div>
    </div>

    <!-- 2. Explorer Dashboard (When Authenticated) -->
    <div id="explorerSection" style="<?= $isConnected ? '' : 'display: none;' ?>">
        <!-- Quota Overview Widgets -->
        <div class="vault-hero">
            <div class="stat-widget">
                <div class="stat-header">
                    <span>Storage Usage</span>
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7h16m-8 4v6"/></svg>
                </div>
                <div class="stat-value" id="valStorageUsed">0 MB</div>
                <div class="progress-bar-container">
                    <div class="progress-bar" id="barStorage" style="width: 0%;"></div>
                </div>
                <div class="stat-sub" id="subStorageLimit">Quota: Loading...</div>
            </div>

            <div class="stat-widget">
                <div class="stat-header">
                    <span>Active Files</span>
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div class="stat-value" id="valFilesCount">0</div>
                <div class="progress-bar-container">
                    <div class="progress-bar" id="barFiles" style="width: 0%;"></div>
                </div>
                <div class="stat-sub" id="subFilesLimit">Quota: Loading...</div>
            </div>

            <div class="stat-widget">
                <div class="stat-header">
                    <span>Max File Size</span>
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <div class="stat-value" id="valMaxFileSize">Unlimited</div>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: 100%; background: #06b6d4;"></div>
                </div>
                <div class="stat-sub">Per uploaded object</div>
            </div>
        </div>

        <!-- Toolbar & Search -->
        <div class="toolbar">
            <div class="tabs-group">
                <button class="tab-btn active" id="tabActive" onclick="switchTab('active')">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    <span>Active Files</span>
                    <span class="badge-count" id="countActive">0</span>
                </button>
                <button class="tab-btn" id="tabTrash" onclick="switchTab('trash')">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Trash Bin</span>
                    <span class="badge-count" id="countTrash">0</span>
                </button>
            </div>

            <div class="search-and-actions">
                <div class="search-box">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="searchInput" placeholder="Filter files by name..." oninput="handleSearch()">
                </div>
                <button class="btn-upload-primary" onclick="openUploadModal()">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    <span>Upload</span>
                </button>
            </div>
        </div>

        <!-- File Type Filter Chips -->
        <div class="type-filters" id="typeFilters">
            <button class="filter-chip active" onclick="setFilter('all', this)">All Files</button>
            <button class="filter-chip" onclick="setFilter('audio', this)">Audio 🎵</button>
            <button class="filter-chip" onclick="setFilter('image', this)">Images 🖼️</button>
            <button class="filter-chip" onclick="setFilter('document', this)">Documents 📄</button>
            <button class="filter-chip" onclick="setFilter('archive', this)">Archives 📦</button>
        </div>

        <!-- Files Grid Container -->
        <div class="files-grid" id="filesGrid">
            <!-- Dynamic File Cards Rendered by JS -->
        </div>
    </div>
</main>

<!-- Upload Modal -->
<div class="modal" id="uploadModal" onclick="closeOnBackdrop(event, this)">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Upload Object</h3>
            <button class="modal-close" onclick="closeModal('uploadModal')">&times;</button>
        </div>

        <form id="uploadForm" onsubmit="handleUploadSubmit(event)">
            <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                <div class="dropzone-title" id="dropzoneLabel">Click or drag & drop to upload</div>
                <div class="dropzone-sub">Supports Audio (MP3, WAV, OGG, M4A, FLAC, AAC), Images, Documents, and Archives</div>
                <input type="file" id="fileInput" style="display: none;" onchange="handleFileSelected(this)">
            </div>

            <div class="form-row">
                <label for="customFilename">Custom Name (Optional)</label>
                <input type="text" id="customFilename" placeholder="e.g. podcast-episode-1.mp3">
            </div>

            <div class="form-row">
                <label for="fileVisibility">Visibility Mode</label>
                <select id="fileVisibility">
                    <option value="public" selected>Public (Direct stream and CDN shareable)</option>
                    <option value="private">Private (Restricted to authenticated project)</option>
                </select>
            </div>

            <button type="submit" class="btn-connect-submit" id="btnUploadSubmit" style="width: 100%; margin-top: 10px;">
                <span>Upload to Vault</span>
            </button>
        </form>
    </div>
</div>

<!-- Replace Modal -->
<div class="modal" id="replaceModal" onclick="closeOnBackdrop(event, this)">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Replace File Content</h3>
            <button class="modal-close" onclick="closeModal('replaceModal')">&times;</button>
        </div>

        <form id="replaceForm" onsubmit="handleReplaceSubmit(event)">
            <input type="hidden" id="replaceFileId">
            <div class="dropzone" id="replaceDropzone" onclick="document.getElementById('replaceFileInput').click()">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <div class="dropzone-title" id="replaceDropzoneLabel">Select replacement file</div>
                <div class="dropzone-sub">Upload new payload while maintaining the same logical file ID</div>
                <input type="file" id="replaceFileInput" style="display: none;" onchange="handleReplaceFileSelected(this)">
            </div>

            <button type="submit" class="btn-connect-submit" id="btnReplaceSubmit" style="width: 100%;">
                <span>Replace Content</span>
            </button>
        </form>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div class="modal" id="imageModal" onclick="closeOnBackdrop(event, this)">
    <div class="modal-card" style="max-width: 900px; padding: 16px; background: rgba(7, 10, 18, 0.95);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding: 0 8px;">
            <span id="imageModalTitle" style="font-size: 14px; font-weight: 600;">Image Preview</span>
            <button class="modal-close" onclick="closeModal('imageModal')">&times;</button>
        </div>
        <img id="lightboxImg" src="" alt="Preview" style="width: 100%; max-height: 75vh; object-fit: contain; border-radius: 12px;">
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
    let currentTab = 'active';
    let currentFilter = 'all';
    let cachedFiles = [];
    let isConnected = <?= $isConnected ? 'true' : 'false' ?>;

    // Initialize
    document.addEventListener('DOMContentLoaded', () => {
        if (isConnected) {
            loadStatus();
            loadFiles();
        }
    });

    // Handle Connect
    async function handleConnect(e) {
        e.preventDefault();
        const input = document.getElementById('inputApiKey');
        const btn = document.getElementById('btnConnect');
        const key = input.value.trim();
        if (!key) return;

        btn.disabled = true;
        btn.innerHTML = '<span>Verifying key...</span>';

        try {
            const formData = new FormData();
            formData.append('api_key', key);
            const res = await fetch('?action=connect', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (res.ok && data.connected) {
                isConnected = true;
                input.value = '';
                document.getElementById('connectScreen').style.display = 'none';
                document.getElementById('explorerSection').style.display = 'block';
                document.getElementById('navControls').style.display = 'flex';
                document.getElementById('navProjectName').textContent = data.project.name || 'Vault';
                showToast(`Connected to project "${data.project.name}"`);
                loadStatus();
                loadFiles();
            } else {
                showToast(data.error || 'Failed to authenticate API key.', true);
            }
        } catch (err) {
            showToast('Network error while verifying API key.', true);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span>Authenticate & Open Vault</span>';
        }
    }

    // Disconnect
    async function disconnect() {
        try {
            await fetch('?action=disconnect', { method: 'POST' });
        } finally {
            isConnected = false;
            document.getElementById('connectScreen').style.display = 'block';
            document.getElementById('explorerSection').style.display = 'none';
            document.getElementById('navControls').style.display = 'none';
            document.getElementById('filesGrid').innerHTML = '';
            showToast('Disconnected from vault.');
        }
    }

    // Load Project Quota / Status
    async function loadStatus() {
        try {
            const res = await fetch('?action=api_status');
            const data = await res.json();
            if (res.ok && data.connected && data.project) {
                const p = data.project;
                document.getElementById('navProjectName').textContent = p.name;

                const usageBytes = p.usage?.storage_bytes || 0;
                const quotaBytes = p.quota?.max_storage_bytes || 0;
                document.getElementById('valStorageUsed').textContent = formatBytes(usageBytes);
                if (quotaBytes > 0) {
                    const pct = Math.min(100, Math.round((usageBytes / quotaBytes) * 100));
                    document.getElementById('barStorage').style.width = pct + '%';
                    document.getElementById('subStorageLimit').textContent = `Quota: ${formatBytes(quotaBytes)} (${pct}%)`;
                } else {
                    document.getElementById('barStorage').style.width = '20%';
                    document.getElementById('subStorageLimit').textContent = 'Quota: Unlimited';
                }

                const fileCount = p.usage?.file_count || 0;
                const maxFiles = p.quota?.max_file_count || 0;
                document.getElementById('valFilesCount').textContent = fileCount.toLocaleString();
                if (maxFiles > 0) {
                    const pct = Math.min(100, Math.round((fileCount / maxFiles) * 100));
                    document.getElementById('barFiles').style.width = pct + '%';
                    document.getElementById('subFilesLimit').textContent = `Quota: ${maxFiles.toLocaleString()} files (${pct}%)`;
                } else {
                    document.getElementById('barFiles').style.width = '20%';
                    document.getElementById('subFilesLimit').textContent = 'Quota: Unlimited';
                }

                const maxSingle = p.quota?.max_file_size_bytes || 0;
                document.getElementById('valMaxFileSize').textContent = maxSingle > 0 ? formatBytes(maxSingle) : 'Unlimited';
            } else if (data.connected === false) {
                disconnect();
            }
        } catch (e) {
            console.error('Failed to load project status', e);
        }
    }

    // Load Files
    async function loadFiles() {
        const grid = document.getElementById('filesGrid');
        grid.innerHTML = '<div class="empty-state"><h4>Loading files...</h4></div>';

        const endpoint = currentTab === 'active' ? '?action=files' : '?action=trash';
        try {
            const res = await fetch(endpoint);
            const data = await res.json();
            if (res.ok && Array.isArray(data.files)) {
                cachedFiles = data.files;
                if (currentTab === 'active') {
                    document.getElementById('countActive').textContent = data.files.length;
                } else {
                    document.getElementById('countTrash').textContent = data.files.length;
                }
                renderFilesGrid();
            } else {
                grid.innerHTML = `<div class="empty-state"><h4>${data.error?.message || 'Failed to load files.'}</h4></div>`;
            }
        } catch (err) {
            grid.innerHTML = '<div class="empty-state"><h4>Network error while loading files.</h4></div>';
        }
    }

    // Switch Tabs
    function switchTab(tab) {
        currentTab = tab;
        document.getElementById('tabActive').classList.toggle('active', tab === 'active');
        document.getElementById('tabTrash').classList.toggle('active', tab === 'trash');
        loadFiles();
    }

    // Filter Chips
    function setFilter(type, btn) {
        currentFilter = type;
        document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        renderFilesGrid();
    }

    // Search
    function handleSearch() {
        renderFilesGrid();
    }

    // Render Cards in Grid
    function renderFilesGrid() {
        const grid = document.getElementById('filesGrid');
        const query = document.getElementById('searchInput').value.toLowerCase().trim();

        const filtered = cachedFiles.filter(file => {
            const nameMatch = file.original_name.toLowerCase().includes(query) || String(file.id).includes(query);
            if (!nameMatch) return false;

            if (currentFilter === 'all') return true;
            const category = getFileCategory(file);
            return category === currentFilter;
        });

        if (filtered.length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                    </svg>
                    <h4>No files found</h4>
                    <p>${currentTab === 'active' ? 'Upload an audio file, image, or document to get started.' : 'Trash bin is empty.'}</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = filtered.map(file => renderFileCard(file)).join('');
    }

    function getFileCategory(file) {
        const mime = (file.mime_type || '').toLowerCase();
        const ext = file.original_name.split('.').pop().toLowerCase();

        if (mime.startsWith('audio/') || ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'weba'].includes(ext)) return 'audio';
        if (mime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ico'].includes(ext)) return 'image';
        if (mime.includes('pdf') || mime.includes('document') || mime.includes('sheet') || mime.includes('text') || ['pdf', 'txt', 'docx', 'xlsx', 'csv', 'md'].includes(ext)) return 'document';
        if (mime.includes('zip') || mime.includes('tar') || mime.includes('gzip') || ['zip', 'tar', 'gz'].includes(ext)) return 'archive';
        return 'other';
    }

    function renderFileCard(file) {
        const isTrashed = (currentTab === 'trash');
        const category = getFileCategory(file);
        const isAudio = (category === 'audio');
        const isImage = (category === 'image');
        const isPublic = (file.visibility === 'public');
        const directUrl = file.url || `?action=download&id=${file.id}`;

        let previewHtml = '';
        if (isImage && !isTrashed) {
            previewHtml = `
                <div class="card-preview">
                    <img src="${directUrl}" alt="${escapeHtml(file.original_name)}" loading="lazy" onclick="openLightbox('${directUrl}', '${escapeHtml(file.original_name)}')">
                </div>
            `;
        } else if (isAudio && !isTrashed) {
            previewHtml = `
                <div class="card-preview">
                    <div class="audio-preview-box">
                        <div class="audio-wave-anim">
                            <div class="audio-bar"></div>
                            <div class="audio-bar"></div>
                            <div class="audio-bar"></div>
                            <div class="audio-bar"></div>
                            <div class="audio-bar"></div>
                            <div class="audio-bar"></div>
                        </div>
                        <audio class="inline-audio-player" controls preload="none">
                            <source src="${directUrl}" type="${file.mime_type || 'audio/mpeg'}">
                            Your browser does not support audio streaming.
                        </audio>
                    </div>
                </div>
            `;
        } else {
            previewHtml = `
                <div class="card-preview">
                    <div class="generic-file-preview">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <span>${escapeHtml(file.original_name.split('.').pop().toUpperCase() || 'FILE')}</span>
                    </div>
                </div>
            `;
        }

        let badgeClass = 'badge-doc';
        if (isAudio) badgeClass = 'badge-audio';
        else if (isImage) badgeClass = 'badge-image';
        if (isTrashed) badgeClass = 'badge-trash';

        const badgeLabel = isTrashed ? 'TRASHED' : (isAudio ? 'AUDIO' : (isImage ? 'IMAGE' : (isPublic ? 'PUBLIC' : 'PRIVATE')));

        let actionsHtml = '';
        if (isTrashed) {
            actionsHtml = `
                <a href="?action=download&id=${file.id}" class="btn-card" download="${escapeHtml(file.original_name)}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Download</span>
                </a>
                <button class="btn-card success" onclick="restoreFile(${file.id})">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Restore</span>
                </button>
                <button class="btn-card danger" onclick="purgeFile(${file.id})">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Purge</span>
                </button>
            `;
        } else {
            actionsHtml = `
                <button class="btn-card" onclick="copyStreamUrl('${directUrl}')" title="Copy public stream link">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    <span>Copy URL</span>
                </button>
                <a href="?action=download&id=${file.id}" class="btn-card" download="${escapeHtml(file.original_name)}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Download</span>
                </a>
                <button class="btn-card" onclick="openReplaceModal(${file.id})" title="Replace payload">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
                <button class="btn-card danger" onclick="trashFile(${file.id})" title="Move to trash">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            `;
        }

        return `
            <div class="file-card" id="file-${file.id}">
                ${previewHtml}
                <div class="card-body">
                    <div class="file-name-row">
                        <div class="file-title" title="${escapeHtml(file.original_name)}">${escapeHtml(file.original_name)}</div>
                        <span class="badge-type ${badgeClass}">${badgeLabel}</span>
                    </div>
                    <div class="file-meta-row">
                        <span>#${file.id}</span>
                        <span>${formatBytes(file.size_bytes)}</span>
                        <span>${formatDate(file.created_at)}</span>
                    </div>
                    <div class="card-actions">
                        ${actionsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    // File Actions (Trash, Restore, Purge)
    async function trashFile(id) {
        if (!confirm('Move this file to trash?')) return;
        try {
            const res = await fetch(`?action=delete&id=${id}`, { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                showToast('File moved to trash.');
                loadFiles();
                loadStatus();
            } else {
                showToast(data.error?.message || 'Failed to move to trash.', true);
            }
        } catch (e) {
            showToast('Network error while moving file to trash.', true);
        }
    }

    async function restoreFile(id) {
        try {
            const res = await fetch(`?action=restore&id=${id}`, { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                showToast('File restored to active vault.');
                loadFiles();
                loadStatus();
            } else {
                showToast(data.error?.message || 'Failed to restore file.', true);
            }
        } catch (e) {
            showToast('Network error while restoring file.', true);
        }
    }

    async function purgeFile(id) {
        if (!confirm('Permanently delete this file? This action CANNOT be undone.')) return;
        try {
            const res = await fetch(`?action=purge&id=${id}`, { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                showToast('File permanently purged.');
                loadFiles();
                loadStatus();
            } else {
                showToast(data.error?.message || 'Failed to purge file.', true);
            }
        } catch (e) {
            showToast('Network error while purging file.', true);
        }
    }

    // Upload Handling
    function openUploadModal() {
        document.getElementById('uploadModal').classList.add('active');
        document.getElementById('uploadForm').reset();
        document.getElementById('dropzoneLabel').textContent = 'Click or drag & drop to upload';
    }

    function handleFileSelected(input) {
        if (input.files.length > 0) {
            document.getElementById('dropzoneLabel').textContent = input.files[0].name + ' (' + formatBytes(input.files[0].size) + ')';
        }
    }

    async function handleUploadSubmit(e) {
        e.preventDefault();
        const fileInput = document.getElementById('fileInput');
        if (fileInput.files.length === 0) {
            showToast('Please select a file to upload.', true);
            return;
        }

        const btn = document.getElementById('btnUploadSubmit');
        btn.disabled = true;
        btn.innerHTML = '<span>Uploading object...</span>';

        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('visibility', document.getElementById('fileVisibility').value);
        const customName = document.getElementById('customFilename').value.trim();
        if (customName) formData.append('name', customName);

        try {
            const res = await fetch('?action=upload', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (res.ok && data.file) {
                showToast('Object uploaded successfully!');
                closeModal('uploadModal');
                loadFiles();
                loadStatus();
            } else {
                showToast(data.error?.message || 'Failed to upload object.', true);
            }
        } catch (err) {
            showToast('Network error during upload.', true);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span>Upload to Vault</span>';
        }
    }

    // Replace Handling
    function openReplaceModal(fileId) {
        document.getElementById('replaceFileId').value = fileId;
        document.getElementById('replaceForm').reset();
        document.getElementById('replaceDropzoneLabel').textContent = 'Select replacement file';
        document.getElementById('replaceModal').classList.add('active');
    }

    function handleReplaceFileSelected(input) {
        if (input.files.length > 0) {
            document.getElementById('replaceDropzoneLabel').textContent = input.files[0].name + ' (' + formatBytes(input.files[0].size) + ')';
        }
    }

    async function handleReplaceSubmit(e) {
        e.preventDefault();
        const fileId = document.getElementById('replaceFileId').value;
        const input = document.getElementById('replaceFileInput');
        if (input.files.length === 0) {
            showToast('Please select a replacement file.', true);
            return;
        }

        const btn = document.getElementById('btnReplaceSubmit');
        btn.disabled = true;
        btn.innerHTML = '<span>Replacing payload...</span>';

        const formData = new FormData();
        formData.append('file', input.files[0]);

        try {
            const res = await fetch(`?action=replace&id=${fileId}`, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (res.ok && data.file) {
                showToast('File replaced successfully.');
                closeModal('replaceModal');
                loadFiles();
                loadStatus();
            } else {
                showToast(data.error?.message || 'Failed to replace file.', true);
            }
        } catch (err) {
            showToast('Network error during replacement.', true);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<span>Replace Content</span>';
        }
    }

    // Lightbox / Preview
    function openLightbox(url, title) {
        document.getElementById('lightboxImg').src = url;
        document.getElementById('imageModalTitle').textContent = title;
        document.getElementById('imageModal').classList.add('active');
    }

    function copyStreamUrl(url) {
        navigator.clipboard.writeText(url).then(() => {
            showToast('Stream URL copied to clipboard!');
        });
    }

    // Modal Helpers
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    function closeOnBackdrop(e, modal) {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    }

    // Toast Notification
    function showToast(message, isError = false) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast' + (isError ? ' error' : '');
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }

    // Format Helpers
    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        try {
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        } catch (e) {
            return '';
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[m]);
    }
</script>

</body>
</html>
