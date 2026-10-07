<?php

declare(strict_types=1);

namespace FileVault\Lifecycle\Exception;

class LifecycleConsistencyException extends LifecycleException
{
    public function __construct(
        string $message,
        private readonly \Throwable $originalException,
        private readonly \Throwable $compensationException,
        int $code = 0
    ) {
        parent::__construct($message, $code, $originalException);
    }

    public function getOriginalException(): \Throwable
    {
        return $this->originalException;
    }

    public function getCompensationException(): \Throwable
    {
        return $this->compensationException;
    }
}
