<?php

declare(strict_types=1);

namespace FileVault\Http\Security;

use InvalidArgumentException;

final class FileValidator
{
    private const PROHIBITED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'cgi', 'pl', 'py', 'pyc', 'pyo', 'exe', 'com', 'bat', 'cmd', 'vbs', 'vbe',
        'js', 'jse', 'wsf', 'wsh', 'sh', 'bash', 'zsh', 'msi', 'bin', 'dll', 'so',
        'dylib', 'htaccess', 'htpasswd', 'ini', 'env',
        // Macro-enabled office extensions (blocked for client safety)
        'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'ppsm', 'sldm'
    ];

    private const ALLOWED_MIME_TYPES = [
        // Images
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/avif' => ['avif'],
        'image/svg+xml' => ['svg'],
        'image/x-icon' => ['ico'],
        'image/bmp' => ['bmp'],
        // Documents & Text
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'log'],
        'text/csv' => ['csv'],
        'application/json' => ['json'],
        'text/markdown' => ['md', 'markdown'],
        'application/xml' => ['xml'],
        'text/xml' => ['xml'],
        'application/rtf' => ['rtf'],
        'text/rtf' => ['rtf'],
        // Microsoft Word
        'application/msword' => ['doc', 'dot'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => ['dotx'],
        // Microsoft Excel
        'application/vnd.ms-excel' => ['xls', 'xlt', 'xla'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template' => ['xltx'],
        // Microsoft PowerPoint
        'application/vnd.ms-powerpoint' => ['ppt', 'pot', 'pps', 'ppa'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.openxmlformats-officedocument.presentationml.template' => ['potx'],
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => ['ppsx'],
        // OpenDocument formats
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/vnd.oasis.opendocument.presentation' => ['odp'],
        // Archives
        'application/zip' => ['zip'],
        'application/x-zip-compressed' => ['zip'],
        'application/gzip' => ['gz'],
        'application/x-tar' => ['tar'],
        // Audio
        'audio/mpeg' => ['mp3'],
        'audio/mp3' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/x-wav' => ['wav'],
        'audio/wave' => ['wav'],
        'audio/ogg' => ['ogg', 'oga'],
        'audio/opus' => ['opus', 'ogg'],
        'application/ogg' => ['ogg', 'opus'],
        'audio/mp4' => ['m4a', 'mp4'],
        'audio/x-m4a' => ['m4a'],
        'audio/aac' => ['aac'],
        'audio/x-aac' => ['aac'],
        'audio/flac' => ['flac'],
        'audio/x-flac' => ['flac'],
        'audio/webm' => ['weba', 'webm'],
        // Video
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/quicktime' => ['mov'],
    ];

    /**
     * Validates an uploaded file.
     *
     * @param string $tempFilePath Path to the uploaded file on disk
     * @param string $originalName Client-provided filename
     * @param string $clientMimeType Client-provided MIME type
     * @return array{safeName: string, mimeType: string} Validated filename and detected MIME type
     * @throws InvalidArgumentException
     */
    public static function validate(string $tempFilePath, string $originalName, string $clientMimeType): array
    {
        $safeName = self::sanitizeFilename($originalName);
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

        if ($ext === '' || in_array($ext, self::PROHIBITED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf('File extension ".%s" is not permitted.', $ext));
        }

        // Check for double extension attacks, e.g. "shell.php.jpg"
        $parts = explode('.', strtolower($safeName));
        if (count($parts) > 2) {
            foreach (array_slice($parts, 1, -1) as $middleExt) {
                if (in_array($middleExt, self::PROHIBITED_EXTENSIONS, true)) {
                    throw new InvalidArgumentException('Filename contains a prohibited extension.');
                }
            }
        }

        // Server-side MIME inspection
        $detectedMime = self::detectMimeType($tempFilePath);
        $mimeToUse = $detectedMime ?: strtolower(trim($clientMimeType));

        // OpenXML documents (docx, xlsx, pptx, odt) are zip packages under the hood
        $openXmlMap = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
        ];

        // Legacy binary office documents
        $legacyOfficeMap = [
            'doc' => 'application/msword',
            'dot' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'xlt' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pot' => 'application/vnd.ms-powerpoint',
            'pps' => 'application/vnd.ms-powerpoint',
            'rtf' => 'application/rtf',
        ];

        // Audio formats mapping
        $audioMap = [
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'opus' => 'audio/opus',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'weba' => 'audio/webm',
        ];

        $audioAllowedMimes = [
            'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave',
            'audio/ogg', 'audio/opus', 'application/ogg', 'audio/mp4', 'audio/x-m4a',
            'audio/aac', 'audio/x-aac', 'audio/flac', 'audio/x-flac', 'audio/webm',
            'video/mp4', 'video/webm', 'application/octet-stream',
        ];

        if (!array_key_exists($mimeToUse, self::ALLOWED_MIME_TYPES)) {
            // Check if finfo detected a container format for an office document
            if (in_array($detectedMime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true) && isset($openXmlMap[$ext])) {
                $mimeToUse = $openXmlMap[$ext];
            } elseif (in_array($detectedMime, ['application/x-ole-storage', 'application/vnd.ms-office', 'application/octet-stream', 'application/x-cfb'], true) && isset($legacyOfficeMap[$ext])) {
                $mimeToUse = $legacyOfficeMap[$ext];
            } elseif (isset($audioMap[$ext]) && (in_array($detectedMime, $audioAllowedMimes, true) || in_array($mimeToUse, $audioAllowedMimes, true))) {
                $mimeToUse = $audioMap[$ext];
            } elseif ($detectedMime === 'text/plain' && in_array($ext, ['csv', 'json', 'md', 'xml', 'rtf'], true)) {
                $mimeToUse = match ($ext) {
                    'csv' => 'text/csv',
                    'json' => 'application/json',
                    'md' => 'text/markdown',
                    'xml' => 'application/xml',
                    'rtf' => 'application/rtf',
                    default => 'text/plain',
                };
            } else {
                throw new InvalidArgumentException(sprintf('MIME type "%s" is not allowed.', $mimeToUse));
            }
        } elseif (in_array($mimeToUse, ['application/zip', 'application/x-zip-compressed'], true) && isset($openXmlMap[$ext])) {
            // When finfo returns generic application/zip for a docx/xlsx/pptx file, map to specific MIME
            $mimeToUse = $openXmlMap[$ext];
        } elseif ($mimeToUse === 'video/mp4' && $ext === 'm4a') {
            $mimeToUse = 'audio/mp4';
        }

        // Canonicalize audio aliases to standard browser-playable types
        if (isset($audioMap[$ext]) && in_array($mimeToUse, $audioAllowedMimes, true)) {
            $mimeToUse = $audioMap[$ext];
        }

        return [
            'safeName' => $safeName,
            'mimeType' => $mimeToUse,
        ];
    }

    public static function sanitizeFilename(string $name): string
    {
        // Strip null bytes and control characters
        $name = str_replace(["\0", "\r", "\n"], '', $name);
        $name = basename($name); // removes path traversal sequences

        // Remove dangerous characters
        $name = preg_replace('/[^\w\.\-\ \(\)]/u', '_', $name) ?? 'unnamed_file';
        $name = trim($name, '. ');

        if ($name === '') {
            $name = 'unnamed_file';
        }

        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 190);
            $name = $ext !== '' ? $base . '.' . $ext : $base;
        }

        return $name;
    }

    private static function detectMimeType(string $filePath): ?string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        return null;
    }
}
