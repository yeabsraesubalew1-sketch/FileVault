<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Credential;

use FileVault\ApiKey\Credential\ApiKeyGenerator;
use PHPUnit\Framework\TestCase;

class ApiKeyGeneratorTest extends TestCase
{
    private ApiKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ApiKeyGenerator();
    }

    public function testGeneratedIdsAreAcceptedByApiKeyId(): void
    {
        $result = $this->generator->generate();
        $this->assertSame(16, strlen($result->publicId->bytes));
    }

    public function testEveryGeneratedIdContainsExactly16RawBytes(): void
    {
        $result = $this->generator->generate();
        $this->assertSame(16, strlen($result->publicId->bytes));
    }

    public function testRepeatedGenerationProducesDistinctValues(): void
    {
        $result1 = $this->generator->generate();
        $result2 = $this->generator->generate();

        $this->assertNotSame($result1->publicId->bytes, $result2->publicId->bytes);
        $this->assertNotSame($result1->rawSecretBytes, $result2->rawSecretBytes);
    }

    public function testGeneratedEncodedPublicIdsUseOnlyUrlSafeBase64Characters(): void
    {
        $result = $this->generator->generate();
        $encodedPublicId = substr($result->plaintextCredential, 4, 22);

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\-_]+$/', $encodedPublicId);
    }

    public function testGeneratedPublicIdsContainNoPadding(): void
    {
        $result = $this->generator->generate();
        $encodedPublicId = substr($result->plaintextCredential, 4, 22);

        $this->assertStringNotContainsString('=', $encodedPublicId);
    }

    public function testEncodedPublicIdLengthIs22Characters(): void
    {
        $result = $this->generator->generate();
        $encodedPublicId = substr($result->plaintextCredential, 4, 22);

        $this->assertSame(22, strlen($encodedPublicId));
    }

    public function testGeneratedSecretsDecodeToExactly32Bytes(): void
    {
        $result = $this->generator->generate();
        $this->assertSame(32, strlen($result->rawSecretBytes));
    }

    public function testGeneratedEncodedSecretsContainOnlyUrlSafeBase64Characters(): void
    {
        $result = $this->generator->generate();
        $encodedSecret = substr($result->plaintextCredential, 27);

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\-_]+$/', $encodedSecret);
    }

    public function testEncodedSecretLengthIs43Characters(): void
    {
        $result = $this->generator->generate();
        $encodedSecret = substr($result->plaintextCredential, 27);

        $this->assertSame(43, strlen($encodedSecret));
    }

    public function testCorrectPrefix(): void
    {
        $result = $this->generator->generate();
        $this->assertStringStartsWith('fvk_', $result->plaintextCredential);
    }

    public function testCorrectSeparators(): void
    {
        $result = $this->generator->generate();
        $this->assertSame('fvk_', substr($result->plaintextCredential, 0, 4));
        $this->assertSame('_', substr($result->plaintextCredential, 26, 1));
    }
}
