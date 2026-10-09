<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey;

use FileVault\ApiKey\ApiKeyName;
use FileVault\ApiKey\Exception\InvalidApiKeyNameException;
use PHPUnit\Framework\TestCase;

class ApiKeyNameTest extends TestCase
{
    public function testValidNameAccepted(): void
    {
        $name = new ApiKeyName('Production Key');
        $this->assertSame('Production Key', $name->value);
        $this->assertSame('Production Key', (string) $name);
    }

    public function testEmptyNameRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not be empty.');
        new ApiKeyName('');
    }

    public function testWhitespaceOnlyNameRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not consist only of whitespace.');
        new ApiKeyName('   ');
    }

    public function testLeadingWhitespaceRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not have leading or trailing whitespace.');
        new ApiKeyName(' Production');
    }

    public function testTrailingWhitespaceRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not have leading or trailing whitespace.');
        new ApiKeyName('Production ');
    }

    public function testMoreThan100CharactersRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name cannot exceed 100 characters.');
        
        $longName = str_repeat('a', 101);
        new ApiKeyName($longName);
    }

    public function testExactly100CharactersAccepted(): void
    {
        $nameString = str_repeat('a', 100);
        $name = new ApiKeyName($nameString);
        $this->assertSame($nameString, $name->value);
    }

    public function testOriginalCasingPreserved(): void
    {
        $nameString = 'pRoDuCtIoN kEy';
        $name = new ApiKeyName($nameString);
        $this->assertSame($nameString, $name->value);
    }

    public function testUnicodeWhitespaceOnlyNameRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not consist only of whitespace.');
        
        // Ideographic space (U+3000) and Non-breaking space (U+00A0)
        new ApiKeyName("\u{3000}\u{00A0}");
    }

    public function testLeadingUnicodeWhitespaceRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not have leading or trailing whitespace.');
        
        // Ideographic space (U+3000) before name
        new ApiKeyName("\u{3000}Production");
    }

    public function testTrailingUnicodeWhitespaceRejected(): void
    {
        $this->expectException(InvalidApiKeyNameException::class);
        $this->expectExceptionMessage('API key name must not have leading or trailing whitespace.');
        
        // Non-breaking space (U+00A0) after name
        new ApiKeyName("Production\u{00A0}");
    }
}
