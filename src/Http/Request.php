<?php

declare(strict_types=1);

namespace FileVault\Http;

use FileVault\ApiKey\AuthenticatedApiKey;
use FileVault\Foundation\Support\RequestIdGenerator;
use FileVault\Project\Project;

final class Request
{
    /**
     * @param array<string, string> $headers Normalized lowercase headers
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $parsedBody
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $queryParams,
        public readonly array $parsedBody,
        public readonly array $files,
        public readonly string $requestId,
        public ?AuthenticatedApiKey $authenticatedApiKey = null,
        public ?Project $authenticatedProject = null
    ) {}

    public static function fromGlobals(?string $requestId = null): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = self::normalizePath($path);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = (string) $value;
            }
        }

        $contentType = $headers['content-type'] ?? '';
        $parsedBody = $_POST;

        if (str_contains($contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput !== false && $rawInput !== '') {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    $parsedBody = $decoded;
                }
            }
        }

        // Use incoming X-Request-ID if provided and valid, otherwise generate fresh
        $incomingRequestId = $headers['x-request-id'] ?? null;
        if ($incomingRequestId !== null && preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $incomingRequestId)) {
            $finalRequestId = $incomingRequestId;
        } else {
            $finalRequestId = (string) RequestIdGenerator::generate();
        }

        return new self(
            method: $method,
            uri: $uri,
            path: rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
            headers: $headers,
            queryParams: $_GET,
            parsedBody: $parsedBody,
            files: $_FILES,
            requestId: $requestId ?? $finalRequestId
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function apiKey(): ?string
    {
        // 1. Check X-API-Key header
        $xApiKey = $this->header('x-api-key');
        if ($xApiKey !== null && trim($xApiKey) !== '') {
            return trim($xApiKey);
        }

        // 2. Check Authorization: Bearer <key>
        $auth = $this->header('authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'])) {
            return null;
        }

        return $file;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public static function normalizePath(string $rawPath): string
    {
        $path = $rawPath;

        // 1. Strip base prefix from APP_URL if configured with a subpath
        $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
        if ($appUrl !== '') {
            $parsedPath = parse_url($appUrl, PHP_URL_PATH);
            if (is_string($parsedPath)) {
                $basePrefix = rtrim($parsedPath, '/');
                if ($basePrefix !== '' && str_starts_with($path, $basePrefix)) {
                    $path = substr($path, strlen($basePrefix));
                }
            }
        }

        // 2. Strip base prefix from SCRIPT_NAME if hosted in a subfolder (e.g. /website_973c67d0/public/index.php)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName !== '') {
            $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            $candidates = [
                $scriptDir,
                preg_replace('#/public$#', '', $scriptDir),
            ];
            foreach ($candidates as $prefix) {
                if ($prefix !== null && $prefix !== '' && $prefix !== '/' && str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                    break;
                }
            }
        }

        $normalized = '/' . ltrim($path, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized === '' ? '/' : $normalized;
    }
}
