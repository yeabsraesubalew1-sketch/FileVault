<?php

declare(strict_types=1);

namespace FileVault\Http;

use FileVault\Observability\ErrorEnvelope;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param (callable(): void)|null $streamCallback
     */
    /** @var resource|null */
    public $streamResource = null;

    public function __construct(
        public int $statusCode = 200,
        public array $headers = [],
        public ?string $body = null,
        public $streamCallback = null
    ) {}

    public function __destruct()
    {
        $this->closeStream();
    }

    public function closeStream(): void
    {
        if (is_resource($this->streamResource)) {
            @fclose($this->streamResource);
            $this->streamResource = null;
        }
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{"error":{"code":"internal_error","message":"Failed to serialize JSON response.","details":null}}';
            $status = 500;
        }

        return new self($status, $headers, $body);
    }

    public static function error(ErrorEnvelope $envelope, int $status = 500, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['X-Request-ID'] = $envelope->requestId;

        return new self($status, $headers, $envelope->toJson());
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($status, $headers, $html);
    }

    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        // If redirect is an internal absolute path (e.g. /admin), prefix with base path if configured
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $appUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
            if ($appUrl !== '') {
                $basePrefix = rtrim((string) parse_url($appUrl, PHP_URL_PATH), '/');
                if ($basePrefix !== '' && !str_starts_with($url, $basePrefix . '/') && $url !== $basePrefix) {
                    $url = $basePrefix . $url;
                }
            }
        }

        $headers['Location'] = $url;
        return new self($status, $headers, '');
    }

    public static function notModified(string $etag, array $headers = []): self
    {
        $headers['ETag'] = '"' . trim($etag, '"') . '"';
        $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        return new self(304, $headers, '');
    }

    /**
     * @param resource $streamResource
     */
    public static function fileStream(
        $streamResource,
        int $sizeBytes,
        string $mimeType,
        string $filename,
        bool $asDownload = false,
        ?string $etag = null,
        ?string $lastModified = null
    ): self {
        $disposition = $asDownload ? 'attachment' : 'inline';
        $encodedFilename = rawurlencode($filename);

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $sizeBytes,
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, addcslashes($filename, '"'), $encodedFilename),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Accept-Ranges' => 'bytes',
        ];

        if ($etag !== null) {
            $headers['ETag'] = '"' . trim($etag, '"') . '"';
        }

        if ($lastModified !== null) {
            $headers['Last-Modified'] = $lastModified;
        }

        $streamCallback = function () use ($streamResource) {
            try {
                $out = fopen('php://output', 'wb');
                if ($out !== false) {
                    stream_copy_to_stream($streamResource, $out);
                    fclose($out);
                }
            } finally {
                if (is_resource($streamResource)) {
                    fclose($streamResource);
                }
            }
        };

        $response = new self(200, $headers, null, $streamCallback);
        $response->streamResource = $streamResource;
        return $response;
    }

    public static function empty(int $status = 204, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        // Per RFC 9110, HEAD responses must not send the message body
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            if ($this->streamResource !== null && is_resource($this->streamResource)) {
                fclose($this->streamResource);
            }
            return;
        }

        if ($this->streamCallback !== null && is_callable($this->streamCallback)) {
            ($this->streamCallback)();
        } elseif ($this->body !== null) {
            echo $this->body;
        }
    }
}
