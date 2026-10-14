<?php

declare(strict_types=1);

namespace FileVault\Tests\Http;

use FileVault\Http\Security\FileValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileValidator::class)]
final class FileValidatorTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'fv_val_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }

    public function testRejectsProhibitedExtensions(): void
    {
        file_put_contents($this->tempFile, '<?php echo 1;');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not permitted');

        FileValidator::validate($this->tempFile, 'exploit.php', 'application/x-php');
    }

    public function testRejectsDoubleExtensionAttacks(): void
    {
        file_put_contents($this->tempFile, 'payload');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prohibited extension');

        FileValidator::validate($this->tempFile, 'shell.php.png', 'image/png');
    }

    public function testSanitizesFilename(): void
    {
        $sanitized = FileValidator::sanitizeFilename("../../etc/passwd");
        $this->assertSame('passwd', $sanitized);

        $sanitized2 = FileValidator::sanitizeFilename("photo (1) - final.jpeg");
        $this->assertSame('photo (1) - final.jpeg', $sanitized2);
    }

    public function testValidatesImageFile(): void
    {
        // 1x1 transparent PNG bytes
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        file_put_contents($this->tempFile, $pngBytes);

        $result = FileValidator::validate($this->tempFile, 'test_image.png', 'image/png');
        $this->assertSame('test_image.png', $result['safeName']);
        $this->assertSame('image/png', $result['mimeType']);
    }

    public function testValidatesOfficeDocuments(): void
    {
        // Minimal valid PK zip signature (OpenXML files are zip packages)
        $zipHeader = "PK\x03\x04\x14\x00\x00\x00\x08\x00" . str_repeat("\x00", 20);
        file_put_contents($this->tempFile, $zipHeader);

        // Word DOCX
        $docxRes = FileValidator::validate($this->tempFile, 'Quarterly_Report.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertSame('Quarterly_Report.docx', $docxRes['safeName']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docxRes['mimeType']);

        // Excel XLSX
        $xlsxRes = FileValidator::validate($this->tempFile, 'Financial_Model.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertSame('Financial_Model.xlsx', $xlsxRes['safeName']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsxRes['mimeType']);

        // PowerPoint PPTX
        $pptxRes = FileValidator::validate($this->tempFile, 'Pitch_Deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $this->assertSame('Pitch_Deck.pptx', $pptxRes['safeName']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.presentationml.presentation', $pptxRes['mimeType']);
    }

    public function testRejectsMacroEnabledOfficeExtensions(): void
    {
        file_put_contents($this->tempFile, "PK\x03\x04" . str_repeat("\x00", 26));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not permitted');

        FileValidator::validate($this->tempFile, 'malicious_macro.docm', 'application/vnd.ms-word.document.macroEnabled.12');
    }
}
