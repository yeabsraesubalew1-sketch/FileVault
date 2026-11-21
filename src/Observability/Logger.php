<?php

declare(strict_types=1);

namespace FileVault\Observability;

final class Logger
{
    public function __construct(
        private readonly ?string $logFile = null
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $record = [
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $this->sanitizeContext($context),
        ];

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode(['timestamp' => date('c'), 'level' => 'ERROR', 'message' => 'Log JSON encoding failed']);
        }

        if ($this->logFile !== null && $this->logFile !== '') {
            @file_put_contents($this->logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
        } else {
            error_log($json);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'secret', 'key', 'token', 'authorization', 'secret_bytes', 'raw_secret'];
        $clean = [];

        foreach ($context as $k => $v) {
            $lower = strtolower((string) $k);
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $clean[$k] = $this->sanitizeContext($v);
            } elseif ($v instanceof \Throwable) {
                $clean[$k] = [
                    'class' => get_class($v),
                    'message' => $v->getMessage(),
                    'code' => $v->getCode(),
                    'file' => $v->getFile(),
                    'line' => $v->getLine(),
                ];
            } else {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }
}
