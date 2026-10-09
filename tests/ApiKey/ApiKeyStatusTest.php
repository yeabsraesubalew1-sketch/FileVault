<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey;

use FileVault\ApiKey\ApiKeyStatus;
use PHPUnit\Framework\TestCase;

class ApiKeyStatusTest extends TestCase
{
    public function testOnlyActiveAndRevokedExist(): void
    {
        $cases = ApiKeyStatus::cases();
        $this->assertCount(2, $cases);
        
        $values = array_map(fn($status) => $status->value, $cases);
        $this->assertContains('active', $values);
        $this->assertContains('revoked', $values);
    }
}
