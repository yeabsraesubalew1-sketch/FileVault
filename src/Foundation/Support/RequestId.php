<?php

declare(strict_types=1);

namespace FileVault\Foundation\Support;

/**
 * A strongly typed Request ID primitive.
 */
final readonly class RequestId
{
    public function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
