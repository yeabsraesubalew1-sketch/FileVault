<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Credential;

use FileVault\ApiKey\ApiKey;
use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\ApiKeyStatus;
use FileVault\ApiKey\Credential\ApiKeyCredentialParser;
use FileVault\ApiKey\Exception\MalformedApiKeyCredentialException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SecretLeakageRegressionTest extends TestCase
{
    public function testPlaintextSecretIsNotStoredInApiKey(): void
    {
        $reflection = new ReflectionClass(ApiKey::class);
        $properties = array_map(fn($prop) => $prop->getName(), $reflection->getProperties());
        
        $this->assertNotContains('secret', $properties);
        $this->assertNotContains('rawSecret', $properties);
        $this->assertNotContains('plaintextCredential', $properties);
    }

    public function testParserExceptionDoesNotContainCredentialMaterial(): void
    {
        $parser = new ApiKeyCredentialParser();
        $invalidCredential = 'fvk_AbCdEfGhIjKlMnOpQrStUv_WxYz123456789012345678901234567890';
        
        try {
            $parser->parse($invalidCredential);
            $this->fail('Expected exception was not thrown.');
        } catch (MalformedApiKeyCredentialException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString('AbCdEfGhIjKlMnOpQrStUv', $message);
            $this->assertStringNotContainsString('WxYz', $message);
            $this->assertStringNotContainsString($invalidCredential, $message);
        }
    }
}
