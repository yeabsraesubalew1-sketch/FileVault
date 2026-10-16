<?php

declare(strict_types=1);

// 1. Load client configuration from .env in this directory
$envFile = __DIR__ . '/.env';
$config = [
    'FILEVAULT_API_URL' => 'http://localhost:8000',
    'FILEVAULT_API_KEY' => '',
];

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
    // 1. Try reading APP_URL from parent .env
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

    // 2. Auto-detect from current web server host and script path
    if (($rawApiUrl === '' || $rawApiUrl === 'http://localhost:8000') && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $subpath = preg_replace('#/client(/.*)?$#i', '', $_SERVER['REQUEST_URI'] ?? '');
        $rawApiUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($subpath, '/');
    }
}

$apiUrl = rtrim($rawApiUrl ?: 'http://localhost:8000', '/');
$apiKey = $config['FILEVAULT_API_KEY'] ?? '';

// Helper for sending server-to-server HTTP requests to FileVault API
function callFileVault(string $url, string $method = 'GET', ?array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

// 2. Handle internal AJAX actions
$action = $_GET['action'] ?? null;

if ($action === 'api_status') {
    header('Content-Type: application/json');
    if ($apiKey === '') {
        echo json_encode(['connected' => false, 'error' => 'API Key is empty. Configure client/.env']);
        exit;
    }

    $res = callFileVault($apiUrl . '/api/v1/project', 'GET', [
        'X-API-Key: ' . $apiKey,
        'Accept: application/json',
    ]);

    if ($res['status'] === 200) {
        echo json_encode(['connected' => true, 'project' => $res['data']['project'] ?? null]);
    } else {
        $msg = $res['data']['error']['message'] ?? 'Unable to connect to FileVault.';
        echo json_encode(['connected' => false, 'error' => $msg, 'status' => $res['status']]);
    }
    exit;
}

if ($action === 'files') {
    header('Content-Type: application/json');
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

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
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
                str_starts_with($lower, 'x-content-type-options:')
            ) {
                header($clean);
            }
            return $len;
        },
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) {
        http_response_code($status);
    }
    exit;
}

if ($action === 'trash') {
    header('Content-Type: application/json');
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

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
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

if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
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

if ($action === 'purge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
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

// 3. Render Web Interface
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileVault — Explorer & Upload Interface</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #090d16;
            --bg-surface: #101726;
            --bg-card: rgba(22, 30, 48, 0.7);
            --bg-card-hover: rgba(30, 41, 64, 0.85);
            --border: rgba(255, 255, 255, 0.08);
            --border-focus: rgba(99, 102, 241, 0.5);
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.25);
            --accent-cyan: #06b6d4;
            --accent-emerald: #10b981;
            --accent-rose: #f43f5e;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-sub: #64748b;
            --radius-lg: 16px;
            --radius-md: 10px;
            --radius-sm: 6px;
            --shadow-card: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 25px rgba(99, 102, 241, 0.2);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            line-height: 1.5;
            background-image: 
                radial-gradient(at 15% 15%, rgba(99, 102, 241, 0.12) 0px, transparent 50%),
                radial-gradient(at 85% 85%, rgba(6, 182, 212, 0.08) 0px, transparent 50%);
            background-attachment: fixed;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 24px;
        }

        /* Header */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 36px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, var(--primary), var(--accent-cyan));
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-glow);
        }

        .brand-icon svg {
            width: 24px;
            height: 24px;
            color: #ffffff;
        }

        .brand-text h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
            background: linear-gradient(to right, #ffffff, #cbd5e1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-text p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 500;
            background: var(--bg-surface);
            border: 1px solid var(--border);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-sub);
        }

        .status-dot.online {
            background: var(--accent-emerald);
            box-shadow: 0 0 10px var(--accent-emerald);
        }

        .status-dot.offline {
            background: var(--accent-rose);
            box-shadow: 0 0 10px var(--accent-rose);
        }

        /* Project Stats Bar */
        .project-stats-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 32px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            box-shadow: var(--shadow-card);
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-sub);
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text-main);
        }

        .progress-bar-container {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 6px;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent-cyan));
            border-radius: 9999px;
            width: 0%;
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Main Grid */
        .main-layout {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 32px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Upload Card */
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-card);
        }

        .card-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .dropzone {
            border: 2px dashed rgba(255, 255, 255, 0.15);
            border-radius: var(--radius-md);
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: rgba(255, 255, 255, 0.02);
            position: relative;
        }

        .dropzone:hover, .dropzone.dragover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.06);
            transform: translateY(-2px);
        }

        .dropzone-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 12px;
            color: var(--primary);
            background: var(--primary-glow);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dropzone-text {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-main);
            margin-bottom: 4px;
        }

        .dropzone-sub {
            font-size: 12px;
            color: var(--text-sub);
        }

        .file-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .selected-file-preview {
            display: none;
            margin-top: 16px;
            padding: 12px;
            background: var(--bg-surface);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-size: 13px;
        }

        .selected-file-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--accent-cyan);
        }

        .selected-file-size {
            font-size: 12px;
            color: var(--text-muted);
        }

        .form-group {
            margin-top: 16px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .input-text, .select {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            font-size: 14px;
            outline: none;
            transition: var(--transition);
        }

        .input-text:focus, .select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .btn-primary {
            width: 100%;
            margin-top: 20px;
            padding: 12px 18px;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: none;
            border-radius: var(--radius-md);
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.6);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Files Gallery */
        .gallery-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .filter-tabs {
            display: flex;
            background: var(--bg-surface);
            padding: 4px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            gap: 4px;
        }

        .filter-btn {
            padding: 6px 14px;
            background: transparent;
            border: none;
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .filter-btn.active {
            background: var(--primary);
            color: #ffffff;
        }

        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 18px;
        }

        .file-card {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            position: relative;
        }

        .file-card:hover {
            border-color: rgba(99, 102, 241, 0.4);
            transform: translateY(-3px);
            box-shadow: var(--shadow-card);
        }

        .file-preview {
            width: 100%;
            height: 160px;
            background: #080c14;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        .file-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .file-card:hover .file-preview img {
            transform: scale(1.05);
        }

        .doc-preview-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(30, 41, 59, 0.4) 0%, rgba(8, 12, 20, 0.9) 100%);
        }

        .doc-preview-icon {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease;
        }

        .file-card:hover .doc-preview-icon {
            transform: scale(1.08);
        }

        .doc-word { background: rgba(43, 87, 154, 0.25); color: #60a5fa; border: 1px solid rgba(43, 87, 154, 0.5); }
        .doc-excel { background: rgba(33, 115, 70, 0.25); color: #34d399; border: 1px solid rgba(33, 115, 70, 0.5); }
        .doc-ppt { background: rgba(210, 71, 38, 0.25); color: #fb923c; border: 1px solid rgba(210, 71, 38, 0.5); }
        .doc-pdf { background: rgba(224, 36, 36, 0.25); color: #f87171; border: 1px solid rgba(224, 36, 36, 0.5); }
        .doc-zip { background: rgba(217, 119, 6, 0.25); color: #fbbf24; border: 1px solid rgba(217, 119, 6, 0.5); }
        .doc-generic { background: rgba(99, 102, 241, 0.25); color: #a5b4fc; border: 1px solid rgba(99, 102, 241, 0.5); }

        .type-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-sub);
            border: 1px solid var(--border);
        }

        .type-badge.word { color: #93c5fd; background: rgba(59, 130, 246, 0.12); border-color: rgba(59, 130, 246, 0.3); }
        .type-badge.excel { color: #86efac; background: rgba(34, 197, 94, 0.12); border-color: rgba(34, 197, 94, 0.3); }
        .type-badge.ppt { color: #fdba74; background: rgba(249, 115, 22, 0.12); border-color: rgba(249, 115, 22, 0.3); }
        .type-badge.pdf { color: #fca5a5; background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.3); }

        .doc-icon {
            width: 48px;
            height: 48px;
            color: var(--text-sub);
        }

        .file-info {
            padding: 14px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .file-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .file-actions {
            display: flex;
            gap: 6px;
            padding: 10px 14px;
            border-top: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.2);
        }

        .btn-action {
            flex: 1;
            padding: 6px 10px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .btn-action:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        .btn-action.btn-delete:hover {
            background: var(--accent-rose);
            color: #ffffff;
            border-color: var(--accent-rose);
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            padding: 48px 24px;
            text-align: center;
            color: var(--text-sub);
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            color: #ffffff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            transform: translateY(100px);
            opacity: 0;
            transition: var(--transition);
            z-index: 1000;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-success {
            background: var(--accent-emerald);
        }

        .toast-error {
            background: var(--accent-rose);
        }

        /* Modal Image Viewer */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8);
            border: 1px solid var(--border);
        }

        .modal-content img {
            max-width: 100%;
            max-height: 85vh;
            display: block;
            object-fit: contain;
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <header>
        <div class="brand">
            <div class="brand-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <div class="brand-text">
                <h1>FileVault Client</h1>
                <p>Self-Hosted Storage Interface</p>
            </div>
        </div>

        <div class="status-badge" id="connectionStatus">
            <span class="status-dot" id="statusDot"></span>
            <span id="statusText">Connecting...</span>
        </div>
    </header>

    <!-- Project Stats Overview -->
    <div class="project-stats-card">
        <div class="stat-item">
            <span class="stat-label">Project</span>
            <span class="stat-value" id="projectName">—</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">Storage Usage</span>
            <span class="stat-value" id="storageUsage">—</span>
            <div class="progress-bar-container">
                <div class="progress-bar-fill" id="storageProgress"></div>
            </div>
        </div>
        <div class="stat-item">
            <span class="stat-label">File Count</span>
            <span class="stat-value" id="fileCount">—</span>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-layout">
        <!-- Upload Form -->
        <div class="card">
            <div class="card-title">
                <span>Upload New File</span>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
            </div>

            <form id="uploadForm">
                <div class="dropzone" id="dropzone">
                    <input type="file" class="file-input" id="fileInput" name="file" required>
                    <div class="dropzone-icon">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                    </div>
                    <div class="dropzone-text">Click or drag file here</div>
                    <div class="dropzone-sub">Images, PDF, Word, Excel, PPT, TXT, JSON, ZIP</div>
                </div>

                <div class="selected-file-preview" id="filePreview">
                    <div class="selected-file-name" id="previewName"></div>
                    <div class="selected-file-size" id="previewSize"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="customName">Custom Filename (Optional)</label>
                    <input type="text" class="input-text" id="customName" placeholder="Keep original name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="visibility">Visibility</label>
                    <select class="select" id="visibility">
                        <option value="public" selected>Public (Deliverable via URL)</option>
                        <option value="private">Private (Restricted)</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary" id="uploadBtn">
                    <span>Upload to Vault</span>
                </button>
            </form>
        </div>

        <!-- Gallery / File Explorer -->
        <div class="card">
            <div class="gallery-header">
                <div class="card-title" style="margin-bottom: 0;">
                    <span>Uploaded Files</span>
                </div>

                <div class="filter-tabs">
                    <button class="filter-btn active" data-filter="all">All</button>
                    <button class="filter-btn" data-filter="images">Images</button>
                    <button class="filter-btn" data-filter="docs">Docs</button>
                    <button class="filter-btn" data-filter="trash" style="color: #f43f5e; border-color: rgba(244, 63, 94, 0.3);">Trash</button>
                </div>
            </div>

            <div class="files-grid" id="filesGrid">
                <!-- Dynamically populated -->
            </div>
        </div>
    </div>
</div>

<!-- Modal for Image Viewing -->
<div class="modal" id="imageModal" onclick="this.classList.remove('active')">
    <div class="modal-content" onclick="event.stopPropagation()">
        <img id="modalImg" src="" alt="Full preview">
    </div>
</div>

<!-- Notification Toast -->
<div class="toast" id="toast"></div>

<script>
    const API_URL = <?= json_encode($apiUrl) ?>;
    let allFiles = [];
    let currentFilter = 'all';

    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast show ' + (type === 'error' ? 'toast-error' : 'toast-success');
        setTimeout(() => toast.classList.remove('show'), 3500);
    }

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatTypeBadge(file) {
        const ext = (file.original_name.split('.').pop() || '').toUpperCase();
        const mime = (file.mime_type || '').toLowerCase();

        let badgeClass = 'type-badge';
        let label = ext || 'FILE';

        if (ext === 'DOC' || ext === 'DOCX') {
            badgeClass += ' word';
            label = 'WORD (' + ext + ')';
        } else if (ext === 'XLS' || ext === 'XLSX') {
            badgeClass += ' excel';
            label = 'EXCEL (' + ext + ')';
        } else if (ext === 'PPT' || ext === 'PPTX') {
            badgeClass += ' ppt';
            label = 'POWERPOINT (' + ext + ')';
        } else if (ext === 'PDF') {
            badgeClass += ' pdf';
            label = 'PDF';
        } else if (ext === 'RTF' || ext === 'ODT' || ext === 'ODS' || ext === 'ODP') {
            label = ext;
        } else if (ext === 'TXT' || ext === 'MD' || ext === 'JSON' || ext === 'CSV' || ext === 'XML') {
            label = ext;
        } else if (ext === 'ZIP' || ext === 'TAR' || ext === 'GZ') {
            label = 'ZIP';
        } else if (mime.startsWith('image/')) {
            label = ext || mime.split('/')[1]?.toUpperCase() || 'IMAGE';
        }

        return `<span class="${badgeClass}">${label}</span>`;
    }

    function getDocumentPreviewHtml(file) {
        const ext = (file.original_name.split('.').pop() || '').toLowerCase();
        let cls = 'doc-generic';
        let label = ext.toUpperCase() || 'FILE';

        if (ext === 'doc' || ext === 'docx') {
            cls = 'doc-word';
            label = 'DOCX';
        } else if (ext === 'xls' || ext === 'xlsx') {
            cls = 'doc-excel';
            label = 'XLSX';
        } else if (ext === 'ppt' || ext === 'pptx') {
            cls = 'doc-ppt';
            label = 'PPTX';
        } else if (ext === 'pdf') {
            cls = 'doc-pdf';
            label = 'PDF';
        } else if (ext === 'zip' || ext === 'tar' || ext === 'gz') {
            cls = 'doc-zip';
            label = 'ZIP';
        }

        return `
            <div class="doc-preview-box">
                <div class="doc-preview-icon ${cls}">
                    <span>${label}</span>
                </div>
            </div>
        `;
    }

    async function checkStatus() {
        const statusDot = document.getElementById('statusDot');
        const statusText = document.getElementById('statusText');

        try {
            const res = await fetch('?action=api_status');
            const data = await res.json();

            if (data.connected && data.project) {
                statusDot.className = 'status-dot online';
                statusText.textContent = 'Connected: ' + data.project.name;

                document.getElementById('projectName').textContent = data.project.name;
                const usage = data.project.usage;
                const quota = data.project.quota;

                document.getElementById('storageUsage').textContent = 
                    formatBytes(usage.current_storage_bytes) + ' / ' + (quota.max_storage_bytes > 0 ? formatBytes(quota.max_storage_bytes) : 'Unlimited');

                document.getElementById('storageProgress').style.width = Math.min(usage.storage_percentage, 100) + '%';
                document.getElementById('fileCount').textContent = usage.current_file_count;
            } else {
                statusDot.className = 'status-dot offline';
                statusText.textContent = data.error || 'Connection Failed';
            }
        } catch (e) {
            statusDot.className = 'status-dot offline';
            statusText.textContent = 'Server Offline';
        }
    }

    async function loadFiles() {
        const grid = document.getElementById('filesGrid');
        try {
            const endpoint = (currentFilter === 'trash') ? '?action=trash' : '?action=files';
            const res = await fetch(endpoint);
            const data = await res.json();

            if (!res.ok) {
                grid.innerHTML = `<div class="empty-state"><p>${data.error?.message || 'Failed to load files.'}</p></div>`;
                return;
            }

            allFiles = data.files || [];
            renderFiles();
        } catch (e) {
            grid.innerHTML = '<div class="empty-state"><p>Error connecting to API.</p></div>';
        }
    }

    function renderFiles() {
        const grid = document.getElementById('filesGrid');
        grid.innerHTML = '';

        let filtered = allFiles;
        if (currentFilter === 'images') {
            filtered = allFiles.filter(f => f.mime_type.startsWith('image/'));
        } else if (currentFilter === 'docs') {
            filtered = allFiles.filter(f => !f.mime_type.startsWith('image/'));
        }

        if (filtered.length === 0) {
            const emptyMsg = currentFilter === 'trash' ? 'Trash is empty.' : 'No files found in this category.';
            grid.innerHTML = `
                <div class="empty-state">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>
                    </svg>
                    <p>${emptyMsg}</p>
                </div>
            `;
            return;
        }

        filtered.forEach(file => {
            const isImage = file.mime_type.startsWith('image/');
            const isTrashed = file.status === 'trashed';
            const card = document.createElement('div');
            card.className = 'file-card';

            // Ensure fileUrl includes correct API_URL host/port
            let fileUrl = file.url;
            if (fileUrl && fileUrl.startsWith('http://localhost/') && API_URL.includes(':8000')) {
                fileUrl = fileUrl.replace('http://localhost/', API_URL + '/');
            } else if (!fileUrl && file.storage_key) {
                fileUrl = API_URL + '/files/' + file.storage_key;
            }

            const previewHtml = (isImage && !isTrashed)
                ? `<img src="${fileUrl}" alt="${file.original_name}" loading="lazy" onclick="openModal('${fileUrl}')">`
                : getDocumentPreviewHtml(file);

            const badgeHtml = isTrashed
                ? `<span class="type-badge" style="color: #f43f5e; background: rgba(244,63,94,0.15); border-color: rgba(244,63,94,0.3);">TRASHED</span>`
                : formatTypeBadge(file);

            const actionsHtml = isTrashed ? `
                <a href="?action=download&id=${file.id}" class="btn-action" title="Download trashed file" download="${file.original_name}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Download</span>
                </a>
                <button class="btn-action" onclick="restoreFile(${file.id})" style="color: #34d399;" title="Restore to active">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Restore</span>
                </button>
                <button class="btn-action btn-delete" onclick="purgeFile(${file.id})" title="Permanently delete from storage">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Purge</span>
                </button>
            ` : `
                <a href="?action=download&id=${file.id}" class="btn-action" title="Download file" download="${file.original_name}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Download</span>
                </a>
                <button class="btn-action" onclick="copyUrl('${fileUrl}')" title="Copy public URL">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    <span>Link</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteFile(${file.id})" title="Move to trash">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            `;

            card.innerHTML = `
                <div class="file-preview">
                    ${previewHtml}
                </div>
                <div class="file-info">
                    <div class="file-name" title="${file.original_name}">${file.original_name}</div>
                    <div class="file-meta">
                        <span>${formatBytes(file.size_bytes)}</span>
                        <span>${badgeHtml}</span>
                    </div>
                </div>
                <div class="file-actions">
                    ${actionsHtml}
                </div>
            `;
            grid.appendChild(card);
        });
    }

    function openModal(url) {
        document.getElementById('modalImg').src = url;
        document.getElementById('imageModal').classList.add('active');
    }

    function copyUrl(url) {
        navigator.clipboard.writeText(url).then(() => {
            showToast('Public link copied to clipboard!');
        }).catch(() => {
            showToast('Failed to copy link.', 'error');
        });
    }

    async function deleteFile(id) {
        if (!confirm('Move this file to trash?')) return;

        try {
            const res = await fetch(`?action=delete&id=${id}`, { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                showToast('File moved to trash.');
                loadFiles();
                checkStatus();
            } else {
                showToast(data.error?.message || 'Delete failed.', 'error');
            }
        } catch (e) {
            showToast('Network error while deleting.', 'error');
        }
    }

    async function restoreFile(id) {
        try {
            const res = await fetch(`?action=restore&id=${id}`, { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                showToast('File restored to active storage.');
                loadFiles();
                checkStatus();
            } else {
                showToast(data.error?.message || 'Restore failed.', 'error');
            }
        } catch (e) {
            showToast('Network error while restoring.', 'error');
        }
    }

    async function purgeFile(id) {
        if (!confirm('Permanently purge this file? This cannot be undone!')) return;

        try {
            const res = await fetch(`?action=purge&id=${id}`, { method: 'POST' });
            const data = await res.json();
            if (res.ok) {
                showToast('File permanently purged.');
                loadFiles();
                checkStatus();
            } else {
                showToast(data.error?.message || 'Purge failed.', 'error');
            }
        } catch (e) {
            showToast('Network error while purging.', 'error');
        }
    }

    // Drag and Drop & File Preview
    const fileInput = document.getElementById('fileInput');
    const dropzone = document.getElementById('dropzone');
    const filePreview = document.getElementById('filePreview');
    const previewName = document.getElementById('previewName');
    const previewSize = document.getElementById('previewSize');

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            const f = fileInput.files[0];
            previewName.textContent = f.name;
            previewSize.textContent = formatBytes(f.size);
            filePreview.style.display = 'block';
        }
    });

    ['dragenter', 'dragover'].forEach(name => {
        dropzone.addEventListener(name, (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach(name => {
        dropzone.addEventListener(name, (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });

    // Upload Form Submit
    const uploadForm = document.getElementById('uploadForm');
    const uploadBtn = document.getElementById('uploadBtn');

    uploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!fileInput.files || fileInput.files.length === 0) {
            showToast('Please select a file first.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('visibility', document.getElementById('visibility').value);
        const customName = document.getElementById('customName').value.trim();
        if (customName !== '') {
            formData.append('name', customName);
        }

        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span>Uploading...</span>';

        try {
            const res = await fetch('?action=upload', {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();

            if (res.ok) {
                showToast('File uploaded successfully!');
                uploadForm.reset();
                filePreview.style.display = 'none';
                loadFiles();
                checkStatus();
            } else {
                showToast(data.error?.message || 'Upload failed.', 'error');
            }
        } catch (err) {
            showToast('Network error during upload.', 'error');
        } finally {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<span>Upload to Vault</span>';
        }
    });

    // Filter Buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const prev = currentFilter;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            if (currentFilter === 'trash' || prev === 'trash') {
                loadFiles();
            } else {
                renderFiles();
            }
        });
    });

    // Initial Load
    checkStatus();
    loadFiles();
</script>

</body>
</html>
