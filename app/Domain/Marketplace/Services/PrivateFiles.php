<?php

namespace App\Domain\Marketplace\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private files for the marketplace (tutor photos, qualification evidence):
 * kept on the private `local` disk under marketplace/, never in a public path,
 * and served only by an authorising controller through stream().
 *
 * What is checked: the bytes themselves (never the client's extension or
 * Content-Type), a size cap, and for images a full decode and re-encode to
 * JPEG, which drops EXIF/GPS metadata and anything appended to the image.
 * PDFs are checked by their magic bytes and stored as sent. There is no virus
 * scanner on the shared host; evidence is opened only by operators.
 */
class PrivateFiles
{
    public const DISK = 'local';

    /**
     * @return array{path: string, mime: string, size_bytes: int, sha256: string}
     *
     * @throws ValidationException
     */
    public function storeImage(UploadedFile $file, string $directory, int $maxPixels, string $field = 'file'): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$field => 'That file is not a JPEG, PNG or WebP image.']);
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw ValidationException::withMessages([$field => 'That image is too large to process.']);
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw ValidationException::withMessages([$field => 'That image could not be read.']);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, $maxPixels / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // Flatten transparency onto white: JPEG has no alpha.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);
        $encoded = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';
        Storage::disk(self::DISK)->put($path, $encoded);

        return ['path' => $path, 'mime' => 'image/jpeg', 'size_bytes' => strlen($encoded), 'sha256' => hash('sha256', $encoded)];
    }

    /**
     * Evidence: a PDF (stored as sent, after a magic-byte check) or an image (re-encoded).
     *
     * @return array{path: string, mime: string, size_bytes: int, sha256: string}
     *
     * @throws ValidationException
     */
    public function storeEvidence(UploadedFile $file, string $directory, string $field = 'file'): array
    {
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($head === '%PDF-') {
            $bytes = (string) file_get_contents($file->getRealPath());
            $path = trim($directory, '/').'/'.Str::uuid().'.pdf';
            Storage::disk(self::DISK)->put($path, $bytes);

            return ['path' => $path, 'mime' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }

        try {
            return $this->storeImage($file, $directory, 2400, $field);
        } catch (ValidationException) {
            throw ValidationException::withMessages([$field => 'Evidence must be a PDF or a JPEG, PNG or WebP image.']);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && Storage::disk(self::DISK)->exists($path);
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function stream(string $path, string $mime, string $downloadName, bool $inline = false): StreamedResponse
    {
        $safeName = Str::slug(pathinfo($downloadName, PATHINFO_FILENAME)) ?: 'file';
        $extension = $mime === 'application/pdf' ? 'pdf' : 'jpg';

        return Storage::disk(self::DISK)->response($path, $safeName.'.'.$extension, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
