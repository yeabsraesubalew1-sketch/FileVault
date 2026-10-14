<?php

declare(strict_types=1);

namespace FileVault\Tests\Observability;

use FileVault\Observability\ErrorEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorEnvelope::class)]
final class ErrorEnvelopeTest extends TestCase
{
    public function testEnvelopeToArray(): void
    {
        $envelope = new ErrorEnvelope('quota_exceeded', 'Quota limit reached.', 'req_123', ['violations' => ['file_size']]);
        $array = $envelope->toArray();

        $this->assertSame([
            'error' => [
                'code' => 'quota_exceeded',
                'message' => 'Quota limit reached.',
                'request_id' => 'req_123',
                'details' => ['violations' => ['file_size']],
            ],
        ], $array);
    }

    public function testEnvelopeToJson(): void
    {
        $envelope = new ErrorEnvelope('not_found', 'Resource not found.', 'req_abc');
        $json = $envelope->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('not_found', $decoded['error']['code']);
        $this->assertSame('Resource not found.', $decoded['error']['message']);
        $this->assertSame('req_abc', $decoded['error']['request_id']);
        $this->assertNull($decoded['error']['details']);
    }
}
