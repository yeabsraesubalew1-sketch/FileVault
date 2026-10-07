<?php

declare(strict_types=1);

namespace FileVault\Foundation\Support;

/**
 * Generates unique Request IDs for operational correlation.
 */
final class RequestIdGenerator
{
    /**
     * Generates a new unique Request ID.
     * Example format: req_abc123...
     */
    public static function generate(): RequestId
    {
        // Generates 16 random bytes and hex encodes them
        $bytes = random_bytes(16);
        return new RequestId('req_' . bin2hex($bytes));
    }
}
