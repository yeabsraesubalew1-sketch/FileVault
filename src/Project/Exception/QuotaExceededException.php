<?php

declare(strict_types=1);

namespace FileVault\Project\Exception;

class QuotaExceededException extends \DomainException
{
    /**
     * @param string[] $violations
     */
    public function __construct(string $message, public readonly array $violations = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
