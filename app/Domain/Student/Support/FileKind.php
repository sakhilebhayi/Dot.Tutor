<?php

namespace App\Domain\Student\Support;

/** A file's type in words a learner knows ("PDF", "Word document"), never a MIME string (R-47 polish D-2020). */
final class FileKind
{
    private const NAMES = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG image',
        'image/png' => 'PNG image',
        'image/webp' => 'WebP image',
        'image/gif' => 'GIF image',
        'video/mp4' => 'MP4 video',
        'video/webm' => 'WebM video',
        'audio/mpeg' => 'MP3 audio',
        'audio/mp4' => 'M4A audio',
        'text/plain' => 'Text file',
        'text/csv' => 'CSV spreadsheet',
        'application/msword' => 'Word document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word document',
        'application/vnd.ms-powerpoint' => 'PowerPoint slides',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint slides',
        'application/vnd.ms-excel' => 'Excel spreadsheet',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel spreadsheet',
        'application/vnd.oasis.opendocument.text' => 'OpenDocument text',
        'application/zip' => 'ZIP archive',
    ];

    public static function label(?string $mime): string
    {
        $mime = strtolower(trim((string) $mime));
        if ($mime === '') {
            return 'File';
        }
        if (isset(self::NAMES[$mime])) {
            return self::NAMES[$mime];
        }
        [$kind, $sub] = array_pad(explode('/', $mime, 2), 2, '');
        $sub = strtoupper((string) preg_replace('/^(x-|vnd\.)/', '', explode(';', $sub)[0]));

        return match ($kind) {
            'image' => $sub.' image',
            'video' => $sub.' video',
            'audio' => $sub.' audio',
            default => $sub !== '' ? $sub.' file' : 'File',
        };
    }
}
