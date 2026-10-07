<?php

declare(strict_types=1);

use FileVault\Foundation\Bootstrap\App;

$basePath = dirname(__DIR__);

// Ensure Composer autoloader exists
if (!file_exists($basePath . '/vendor/autoload.php')) {
    if (php_sapi_name() === 'cli') {
        echo "Missing vendor/autoload.php. Run composer install.\n";
    } else {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Internal Server Error\n";
    }
    exit(1);
}

require_once $basePath . '/vendor/autoload.php';

try {
    // Bootstrap the Foundation
    $app = App::bootstrap($basePath);

    // The HTTP/API module (M02) will eventually take over from here.
    // For now, this confirms the bootstrap was successful.
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'message' => 'FileVault Core Foundation initialized successfully.'
        ]);
    }
} catch (\Throwable $e) {
    error_log('FileVault Bootstrap Failure: ' . $e->getMessage());

    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Internal Server Error\n";
    } else if (php_sapi_name() === 'cli') {
        echo "Bootstrap Error: " . $e->getMessage() . "\n";
    }

    exit(1);
}
