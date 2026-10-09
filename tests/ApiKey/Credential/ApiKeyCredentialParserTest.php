<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Credential;

use FileVault\ApiKey\Credential\ApiKeyCredentialParser;
use FileVault\ApiKey\Credential\ApiKeyGenerator;
use FileVault\ApiKey\Exception\MalformedApiKeyCredentialException;
use PHPUnit\Framework\TestCase;

class ApiKeyCredentialParserTest extends TestCase
{
    private ApiKeyCredentialParser $parser;
    private ApiKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->parser = new ApiKeyCredentialParser();
        $this->generator = new ApiKeyGenerator();
    }

    public function testSuccessfulParsingOfValidGeneratedCredential(): void
    {
        $generated = $this->generator->generate();
        $parsed = $this->parser->parse($generated->plaintextCredential);

        $this->assertSame($generated->publicId->bytes, $parsed->publicId->bytes);
        $this->assertSame($generated->rawSecretBytes, $parsed->rawSecretBytes);
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse('');
    }

    public function testRejectsMissingPrefix(): void
    {
        $generated = $this->generator->generate();
        $invalid = substr($generated->plaintextCredential, 4);

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsWrongPrefix(): void
    {
        $generated = $this->generator->generate();
        $invalid = 'bad_' . substr($generated->plaintextCredential, 4);

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsExtraSeparator(): void
    {
        $generated = $this->generator->generate();
        $invalid = $generated->plaintextCredential . '_extra';

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsMissingPublicId(): void
    {
        $generated = $this->generator->generate();
        $secret = substr($generated->plaintextCredential, 27);
        $invalid = 'fvk__' . $secret;

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsMissingSecret(): void
    {
        $generated = $this->generator->generate();
        $publicId = substr($generated->plaintextCredential, 4, 22);
        $invalid = 'fvk_' . $publicId . '_';

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsWrongPublicIdLength(): void
    {
        $generated = $this->generator->generate();
        $publicId = substr($generated->plaintextCredential, 4, 22);
        $secret = substr($generated->plaintextCredential, 27);
        $invalid = 'fvk_' . substr($publicId, 1) . '_' . $secret;

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsWrongSecretLength(): void
    {
        $generated = $this->generator->generate();
        $publicId = substr($generated->plaintextCredential, 4, 22);
        $secret = substr($generated->plaintextCredential, 27);
        $invalid = 'fvk_' . $publicId . '_' . substr($secret, 1);

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }

    public function testRejectsInvalidCharactersInBase64Url(): void
    {
        $generated = $this->generator->generate();
        $publicId = substr($generated->plaintextCredential, 4, 22);
        $secret = substr($generated->plaintextCredential, 27);
        $invalid = 'fvk_' . $publicId . '_' . str_replace($secret[0], '+', $secret);

        $this->expectException(MalformedApiKeyCredentialException::class);
        $this->parser->parse($invalid);
    }
}
