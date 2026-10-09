<?php

declare(strict_types=1);

namespace FileVault\Tests\ApiKey\Credential;

use FileVault\ApiKey\Credential\Base64Url;
use PHPUnit\Framework\TestCase;

class Base64UrlTest extends TestCase
{
    public function testEncodeRemovesPaddingAndUsesUrlSafeCharacters(): void
    {
        // "???" encoded in standard Base64 is "Pz8/"
        $this->assertSame('Pz8_', Base64Url::encode('???'));
        
        // "\xFB\xFF" encoded in standard Base64 is "+/8="
        $this->assertSame('-_8', Base64Url::encode("\xFB\xFF"));
    }

    public function testDecodeHandlesUrlSafeCharactersWithoutPadding(): void
    {
        $this->assertSame('???', Base64Url::decode('Pz8_'));
        $this->assertSame("\xFB\xFF", Base64Url::decode('-_8'));
    }

    public function testDecodeRejectsInvalidCharacters(): void
    {
        // Invalid chars like '+', '/', '='
        $this->assertNull(Base64Url::decode('Pz8/'));
        $this->assertNull(Base64Url::decode('-_8='));
        $this->assertNull(Base64Url::decode('+/8='));
    }

    public function testDecodeRejectsNonCanonicalRepresentations(): void
    {
        // "A" -> encoded canonical is "QQ" (without padding).
        // Standard Base64 is "QQ=="
        $this->assertSame('QQ', Base64Url::encode('A'));
        $this->assertSame('A', Base64Url::decode('QQ'));
        
        // If someone passes "QRE" where the last bits are non-zero, it decodes to "A" in loose base64,
        // but re-encoding it produces "QQ". Thus, it should be rejected.
        // Let's test a known non-canonical string that normally decodes to the same bytes.
        // Base64 'A' is 000000. 'Q' is 16 (010000).
        // 'QQ' -> 010000 010000 -> 01000001 0000 (padding 0s) -> 'A'
        // 'QR' -> 010000 010001 -> 01000001 0001 (padding non-0s) -> 'A' in loose base64.
        // Re-encoding 'A' yields 'QQ', so 'QR' is non-canonical.
        
        // Let's check PHP base64_decode behavior on "QR"
        // base64_decode("QR==") is "A", base64_encode("A") is "QQ==".
        $this->assertNull(Base64Url::decode('QR'));
    }
}
