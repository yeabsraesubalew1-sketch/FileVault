<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

final class Base64Url
{
    public static function encode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    public static function decode(string $data): ?string
    {
        if (preg_match('/^[a-zA-Z0-9\-_]+$/', $data) !== 1) {
            return null;
        }

        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);
        
        if ($decoded === false) {
            return null;
        }

        if (self::encode($decoded) !== $data) {
            return null;
        }

        return $decoded;
    }
}
