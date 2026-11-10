<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Support\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Accepts an upload onto the private learning disk. The type is decided by the bytes (finfo plus a structural check),
 * never by the file name or the browser's claim; sizes are capped; images are decoded and re-encoded so EXIF and
 * other metadata (location, device) are dropped. There is no virus scanner on the shared host. Legacy binary Office
 * files (.doc, .ppt, .xls) and macro-enabled documents are refused: modern Office Open XML and OpenDocument files,
 * PDFs, images and MP4/WebM video are accepted.
 */
class FileIntake
{
    /** Categories and the kinds each accepts. */
    private const CATEGORIES = [
        'document' => ['pdf', 'ooxml', 'odf'],
        'slides' => ['pdf', 'ooxml', 'odf'],
        'image' => ['image'],
        'video' => ['video'],
        'material' => ['pdf', 'ooxml', 'odf', 'image'],
        'library' => ['pdf', 'ooxml', 'odf', 'image', 'video'],
    ];

    private const MAX_IMAGE_PIXELS = 40_000_000;

    public function accept(UploadedFile $file, string $category, string $directory, string $field = 'file'): StoredFile
    {
        $kinds = self::CATEGORIES[$category] ?? throw new \InvalidArgumentException("Unknown upload category {$category}");

        if (! $file->isValid()) {
            $this->fail($field, 'The upload did not arrive complete. Try again.');
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        $isVideo = in_array('video', $kinds, true);
        $capKb = $isVideo && $this->sniff($file->getRealPath()) === 'video'
            ? (int) config('learning.max_video_kb')
            : (int) config('learning.max_upload_kb');
        if ($sizeKb > $capKb) {
            $this->fail($field, 'The file is larger than the '.round($capKb / 1024).' MB limit.');
        }

        $path = $file->getRealPath();
        $kind = $this->sniff($path);
        if ($kind === null || ! in_array($kind, $kinds, true)) {
            $this->fail($field, $this->refusalMessage($kinds));
        }

        [$bytes, $mime, $extension] = match ($kind) {
            'image' => $this->reencodeImage($path, $field),
            'pdf' => [file_get_contents($path), 'application/pdf', 'pdf'],
            'video' => [null, $this->finfo($path), $this->finfo($path) === 'video/webm' ? 'webm' : 'mp4'],
            'ooxml', 'odf' => [file_get_contents($path), ...$this->packageType($path, $field)],
        };

        $name = trim($directory, '/').'/'.Str::random(40).'.'.$extension;
        $disk = Storage::disk('learning');
        if ($bytes === null) {
            $stream = fopen($path, 'rb');
            $disk->writeStream($name, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $sha = hash_file('sha256', $path);
            $size = (int) $file->getSize();
        } else {
            $disk->put($name, $bytes);
            $sha = hash('sha256', $bytes);
            $size = strlen($bytes);
        }

        return new StoredFile(
            path: $name,
            mime: $mime,
            size: $size,
            sha256: $sha,
            originalName: Str::limit(basename($file->getClientOriginalName()), 200, ''),
        );
    }

    /** Decide what the bytes are: pdf, image, video, ooxml, odf, or null. */
    public function sniff(string $path): ?string
    {
        $mime = $this->finfo($path);
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if ($mime === 'application/pdf' && str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return 'image';
        }
        if (in_array($mime, ['video/mp4', 'video/webm'], true)) {
            return 'video';
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) === true) {
                try {
                    if ($zip->locateName('[Content_Types].xml') !== false) {
                        return 'ooxml';
                    }
                    $odfMime = $zip->getFromName('mimetype');
                    if (is_string($odfMime) && str_starts_with($odfMime, 'application/vnd.oasis.opendocument.')) {
                        return 'odf';
                    }
                } finally {
                    $zip->close();
                }
            }
        }

        return null;
    }

    private function finfo(string $path): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    /** @return array{0: string, 1: string} mime and extension of an OOXML or ODF package */
    private function packageType(string $path, string $field): array
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::RDONLY);
        try {
            $types = $zip->getFromName('[Content_Types].xml');
            if (is_string($types)) {
                if (stripos($types, 'macroEnabled') !== false || $zip->locateName('word/vbaProject.bin') !== false
                    || $zip->locateName('ppt/vbaProject.bin') !== false || $zip->locateName('xl/vbaProject.bin') !== false) {
                    $this->fail($field, 'Documents with macros are not accepted. Save it as a normal document first.');
                }
                if ($zip->locateName('word/document.xml') !== false) {
                    return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'];
                }
                if ($zip->locateName('ppt/presentation.xml') !== false) {
                    return ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx'];
                }
                if ($zip->locateName('xl/workbook.xml') !== false) {
                    return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'];
                }
                $this->fail($field, 'That Office file type is not accepted.');
            }
            $odfMime = trim((string) $zip->getFromName('mimetype'));

            return match ($odfMime) {
                'application/vnd.oasis.opendocument.text' => [$odfMime, 'odt'],
                'application/vnd.oasis.opendocument.presentation' => [$odfMime, 'odp'],
                'application/vnd.oasis.opendocument.spreadsheet' => [$odfMime, 'ods'],
                default => $this->fail($field, 'That OpenDocument file type is not accepted.'),
            };
        } finally {
            $zip->close();
        }
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function reencodeImage(string $path, string $field): array
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            $this->fail($field, 'That image could not be read.');
        }
        if ($info[0] * $info[1] > self::MAX_IMAGE_PIXELS) {
            $this->fail($field, 'That image is too large in pixels.');
        }
        if (! function_exists('imagecreatefromstring')) {
            $this->fail($field, 'Image uploads are not available on this server (no image library).');
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            $this->fail($field, 'That image could not be read.');
        }

        ob_start();
        if ($info[2] === IMAGETYPE_JPEG) {
            imagejpeg($image, null, 85);
            $mime = 'image/jpeg';
            $extension = 'jpg';
        } else {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
            $mime = 'image/png';
            $extension = 'png';
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return [$bytes, $mime, $extension];
    }

    /** @param  list<string>  $kinds */
    private function refusalMessage(array $kinds): string
    {
        $names = ['pdf' => 'PDF', 'ooxml' => 'Word, PowerPoint or Excel (.docx, .pptx, .xlsx)', 'odf' => 'OpenDocument', 'image' => 'JPEG, PNG, GIF or WebP image', 'video' => 'MP4 or WebM video'];

        return 'This file type is not accepted here. Accepted: '.implode(', ', array_map(fn ($k) => $names[$k], $kinds)).'.';
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
