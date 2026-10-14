<?php

declare(strict_types=1);

namespace FileVault\Tests\Observability;

use FileVault\ApiKey\Exception\ApiKeyAuthenticationException;
use FileVault\ApiKey\Exception\ApiKeyNotFoundException;
use FileVault\Lifecycle\Exception\InvalidLifecycleOperationException;
use FileVault\Observability\ExceptionMapper;
use FileVault\Persistence\Exception\ConnectionException;
use FileVault\Project\Exception\InvalidProjectNameException;
use FileVault\Project\Exception\ProjectNotFoundException;
use FileVault\Project\Exception\ProjectNotOperationalException;
use FileVault\Project\Exception\QuotaExceededException;
use FileVault\Storage\Exception\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExceptionMapper::class)]
final class ExceptionMapperTest extends TestCase
{
    public function testMapsAuthenticationException(): void
    {
        $res = ExceptionMapper::map(new ApiKeyAuthenticationException('Bad key'), 'req_1');
        $this->assertSame(401, $res['status']);
        $this->assertSame('invalid_api_key', $res['envelope']->code);
        $this->assertSame('Invalid API key credential.', $res['envelope']->message);
    }

    public function testMapsQuotaExceededException(): void
    {
        $res = ExceptionMapper::map(new QuotaExceededException('Exceeded', ['file_size']), 'req_2');
        $this->assertSame(413, $res['status']);
        $this->assertSame('quota_exceeded', $res['envelope']->code);
        $this->assertSame(['violations' => ['file_size']], $res['envelope']->details);

        $resStorage = ExceptionMapper::map(new QuotaExceededException('Exceeded', ['storage']), 'req_3');
        $this->assertSame(422, $resStorage['status']);
    }

    public function testMapsProjectPolicyExceptions(): void
    {
        $res = ExceptionMapper::map(new ProjectNotOperationalException('Disabled'), 'req_4');
        $this->assertSame(403, $res['status']);
        $this->assertSame('project_disabled', $res['envelope']->code);

        $resNotFound = ExceptionMapper::map(new ProjectNotFoundException('Missing'), 'req_5');
        $this->assertSame(404, $resNotFound['status']);
        $this->assertSame('project_not_found', $resNotFound['envelope']->code);
    }

    public function testMapsValidationAndConflictExceptions(): void
    {
        $resVal = ExceptionMapper::map(new InvalidProjectNameException('Bad name'), 'req_6');
        $this->assertSame(400, $resVal['status']);
        $this->assertSame('invalid_request', $resVal['envelope']->code);

        $resConflict = ExceptionMapper::map(new InvalidLifecycleOperationException('Already trashed'), 'req_7');
        $this->assertSame(409, $resConflict['status']);
        $this->assertSame('invalid_lifecycle_transition', $resConflict['envelope']->code);
    }

    public function testMapsInfrastructureExceptions(): void
    {
        $resConn = ExceptionMapper::map(new ConnectionException('DB down'), 'req_8');
        $this->assertSame(503, $resConn['status']);
        $this->assertSame('service_unavailable', $resConn['envelope']->code);

        $resStorage = ExceptionMapper::map(new StorageException('Disk error'), 'req_9');
        $this->assertSame(500, $resStorage['status']);
        $this->assertSame('storage_error', $resStorage['envelope']->code);

        $resGeneric = ExceptionMapper::map(new \RuntimeException('Secret SQL error: select * from users'), 'req_10');
        $this->assertSame(500, $resGeneric['status']);
        $this->assertSame('internal_error', $resGeneric['envelope']->code);
        // Ensure sensitive internal message is not leaked in production
        $this->assertSame('An internal error occurred.', $resGeneric['envelope']->message);
    }
}
