<?php

declare(strict_types=1);

namespace FileVault\Tests\Foundation\Support;

use FileVault\Foundation\Support\RequestIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestIdGenerator::class)]
final class RequestIdGeneratorTest extends TestCase
{
    public function testGeneratesUniqueRequestIds(): void
    {
        $id1 = RequestIdGenerator::generate();
        $id2 = RequestIdGenerator::generate();

        $this->assertNotSame($id1->value, $id2->value);
        $this->assertStringStartsWith('req_', $id1->value);
        $this->assertStringStartsWith('req_', $id2->value);
    }
}
