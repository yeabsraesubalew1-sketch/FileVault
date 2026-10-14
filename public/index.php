<?php

declare(strict_types=1);

use FileVault\Http\Kernel;
use FileVault\Http\Request;

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
    $kernel = Kernel::create($basePath);
    $request = Request::fromGlobals();
    $response = $kernel->handle($request);
    $response->send();
} catch (\Throwable $e) {
    error_log('FileVault Unhandled Failure: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code' => 'internal_error',
                'message' => 'An internal server error occurred.',
                'request_id' => 'req_bootstrap_failure',
                'details' => null,
            ],
        ]);
    }

    exit(1);
}
