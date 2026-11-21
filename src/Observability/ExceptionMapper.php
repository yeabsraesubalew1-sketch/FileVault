<?php

declare(strict_types=1);

namespace FileVault\Observability;

use FileVault\ApiKey\Exception\ApiKeyAuthenticationException;
use FileVault\ApiKey\Exception\ApiKeyNotFoundException;
use FileVault\ApiKey\Exception\DuplicateApiKeyNameException;
use FileVault\ApiKey\Exception\InvalidApiKeyNameException;
use FileVault\ApiKey\Exception\MalformedApiKeyCredentialException;
use FileVault\Lifecycle\Exception\InvalidLifecycleOperationException;
use FileVault\Lifecycle\Exception\LifecycleConsistencyException;
use FileVault\Lifecycle\Exception\LifecycleException;
use FileVault\Persistence\Exception\ConnectionException;
use FileVault\Persistence\Exception\ConstraintViolationException;
use FileVault\Persistence\Exception\PersistenceException;
use FileVault\Persistence\Exception\QueryException;
use FileVault\Persistence\Exception\TransactionException;
use FileVault\Project\Exception\DuplicateProjectNameException;
use FileVault\Project\Exception\InvalidProjectNameException;
use FileVault\Project\Exception\InvalidQuotaException;
use FileVault\Project\Exception\InvalidStatusTransitionException;
use FileVault\Project\Exception\ProjectNotFoundException;
use FileVault\Project\Exception\ProjectNotOperationalException;
use FileVault\Project\Exception\PublicDeliveryNotAllowedException;
use FileVault\Project\Exception\QuotaExceededException;
use FileVault\Storage\Exception\StorageConflictException;
use FileVault\Storage\Exception\StorageException;
use FileVault\Storage\Exception\StorageObjectNotFoundException;
use FileVault\Storage\Exception\StorageOperationException;
use Throwable;

final class ExceptionMapper
{
    /**
     * @return array{status: int, envelope: ErrorEnvelope, logLevel: string}
     */
    public static function map(Throwable $e, string $requestId, bool $debug = false): array
    {
        // 1. Authentication
        if ($e instanceof ApiKeyAuthenticationException || $e instanceof MalformedApiKeyCredentialException) {
            return [
                'status' => 401,
                'envelope' => new ErrorEnvelope('invalid_api_key', 'Invalid API key credential.', $requestId),
                'logLevel' => 'notice',
            ];
        }

        // 2. Project Policies
        if ($e instanceof ProjectNotOperationalException) {
            return [
                'status' => 403,
                'envelope' => new ErrorEnvelope('project_disabled', 'Project is not operational.', $requestId),
                'logLevel' => 'notice',
            ];
        }

        if ($e instanceof PublicDeliveryNotAllowedException) {
            return [
                'status' => 403,
                'envelope' => new ErrorEnvelope('project_restricted', 'Public delivery is not allowed for this project.', $requestId),
                'logLevel' => 'notice',
            ];
        }

        if ($e instanceof ProjectNotFoundException) {
            return [
                'status' => 404,
                'envelope' => new ErrorEnvelope('project_not_found', 'Project not found.', $requestId),
                'logLevel' => 'notice',
            ];
        }

        // 3. Quotas
        if ($e instanceof QuotaExceededException) {
            $status = in_array('file_size', $e->violations, true) ? 413 : 422;
            return [
                'status' => $status,
                'envelope' => new ErrorEnvelope('quota_exceeded', 'Project quota exceeded.', $requestId, [
                    'violations' => $e->violations,
                ]),
                'logLevel' => 'notice',
            ];
        }

        // 4. Resource Not Found
        if ($e instanceof ApiKeyNotFoundException) {
            return [
                'status' => 404,
                'envelope' => new ErrorEnvelope('not_found', 'API key not found.', $requestId),
                'logLevel' => 'notice',
            ];
        }

        if ($e instanceof StorageObjectNotFoundException) {
            return [
                'status' => 404,
                'envelope' => new ErrorEnvelope('file_not_found', 'File not found.', $requestId),
                'logLevel' => 'notice',
            ];
        }

        // 5. Validation / Client Errors
        if (
            $e instanceof InvalidProjectNameException ||
            $e instanceof InvalidApiKeyNameException ||
            $e instanceof InvalidQuotaException
        ) {
            return [
                'status' => 400,
                'envelope' => new ErrorEnvelope('invalid_request', $e->getMessage(), $requestId),
                'logLevel' => 'info',
            ];
        }

        // 6. Conflicts / Transitions
        if ($e instanceof DuplicateProjectNameException || $e instanceof DuplicateApiKeyNameException) {
            return [
                'status' => 409,
                'envelope' => new ErrorEnvelope('invalid_request', $e->getMessage(), $requestId),
                'logLevel' => 'notice',
            ];
        }

        if ($e instanceof InvalidLifecycleOperationException || $e instanceof InvalidStatusTransitionException) {
            return [
                'status' => 409,
                'envelope' => new ErrorEnvelope('invalid_lifecycle_transition', $e->getMessage(), $requestId),
                'logLevel' => 'notice',
            ];
        }

        // 7. Storage Errors
        if ($e instanceof StorageConflictException) {
            return [
                'status' => 409,
                'envelope' => new ErrorEnvelope('storage_error', 'Storage conflict at destination.', $requestId),
                'logLevel' => 'error',
            ];
        }

        if ($e instanceof StorageOperationException || $e instanceof StorageException) {
            return [
                'status' => 500,
                'envelope' => new ErrorEnvelope('storage_error', 'Storage operation failed.', $requestId),
                'logLevel' => 'error',
            ];
        }

        // 8. Lifecycle Consistency
        if ($e instanceof LifecycleConsistencyException) {
            return [
                'status' => 500,
                'envelope' => new ErrorEnvelope('internal_error', 'An internal consistency error occurred.', $requestId),
                'logLevel' => 'critical',
            ];
        }

        if ($e instanceof LifecycleException) {
            $details = $debug ? [
                'cause' => $e->getPrevious()?->getMessage(),
                'cause_class' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
            ] : null;

            return [
                'status' => 500,
                'envelope' => new ErrorEnvelope('internal_error', 'File lifecycle operation failed.', $requestId, $details),
                'logLevel' => 'error',
            ];
        }

        // 9. Database & Persistence
        if ($e instanceof ConnectionException) {
            return [
                'status' => 503,
                'envelope' => new ErrorEnvelope('service_unavailable', 'Database service is temporarily unavailable.', $requestId),
                'logLevel' => 'critical',
            ];
        }

        if (
            $e instanceof QueryException ||
            $e instanceof TransactionException ||
            $e instanceof ConstraintViolationException ||
            $e instanceof PersistenceException ||
            $e instanceof \PDOException
        ) {
            return [
                'status' => 500,
                'envelope' => new ErrorEnvelope('internal_error', 'A database error occurred.', $requestId),
                'logLevel' => 'error',
            ];
        }

        // 10. Default / Unhandled
        $details = $debug ? [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ] : null;

        return [
            'status' => 500,
            'envelope' => new ErrorEnvelope('internal_error', 'An internal error occurred.', $requestId, $details),
            'logLevel' => 'error',
        ];
    }
}
