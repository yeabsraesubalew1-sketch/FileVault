<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\Exception\ApiKeyException;
use PHPUnit\Framework\TestCase;

class ApiKeyIdTest extends TestCase
{
    public function testValid16ByteIdentityAccepted(): void
    {
        $bytes = random_bytes(16);
        $id = new ApiKeyId($bytes);
        $this->assertSame($bytes, $id->bytes);
    }

    public function testInvalidLengthsRejected(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('ApiKeyId must be exactly 16 bytes.');
        new ApiKeyId(random_bytes(15));
    }
    
    public function testLongLengthsRejected(): void
    {
        $this->expectException(ApiKeyException::class);
        $this->expectExceptionMessage('ApiKeyId must be exactly 16 bytes.');
        new ApiKeyId(random_bytes(17));
    }
}
