<?php

declare(strict_types=1);

namespace FileVault\ApiKey;

use FileVault\ApiKey\Exception\InvalidApiKeyNameException;

final readonly class ApiKeyName
{
    public string $value;

    public function __construct(string $name)
    {
        if ($name === '') {
            throw new InvalidApiKeyNameException('API key name must not be empty.');
        }

        if (preg_match('/^\s*$/u', $name) === 1) {
            throw new InvalidApiKeyNameException('API key name must not consist only of whitespace.');
        }

        if (preg_match('/^\s/u', $name) === 1 || preg_match('/\s$/u', $name) === 1) {
            throw new InvalidApiKeyNameException('API key name must not have leading or trailing whitespace.');
        }

        if (mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidApiKeyNameException('API key name cannot exceed 100 characters.');
        }

        $this->value = $name;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
