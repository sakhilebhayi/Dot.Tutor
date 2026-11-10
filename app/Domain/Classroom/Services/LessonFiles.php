<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Models\LessonResource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files shared in a lesson become the lesson's resources (the existing lesson_resources table on the private
 * lesson-resources disk), so they stay with the lesson after it ends. The type is decided from the bytes, images are
 * decoded and re-encoded (metadata and anything appended dropped). There is no virus scanner on the host.
 */
class LessonFiles
{
    public const DISK = 'lesson-resources';

    private const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'text/plain' => 'txt'];

    public function __construct(private readonly SafeguardingGate $gate) {}

    public function upload(Classroom $classroom, User $by, UploadedFile $file, ?string $title): LessonResource
    {
        $this->gate->allows($by, 'upload', $classroom->session)->authorize();

        if (! $file->isValid()) {
            throw new ClassroomException('upload_failed', 'The file did not arrive. Try again.');
        }
        if ($file->getSize() > (int) config('classroom.files.max_kb') * 1024) {
            throw new ClassroomException('file_too_large', 'Files can be up to '.round(config('classroom.files.max_kb') / 1024).' MB.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! isset(self::TYPES[$mime])) {
            throw new ClassroomException('file_type', 'Share a PDF, a PNG or JPEG image, or a plain text file.');
        }
        if ($mime === 'application/pdf' && ! str_starts_with($bytes, '%PDF-')) {
            throw new ClassroomException('file_type', 'That file is not a readable PDF.');
        }
        if (str_starts_with($mime, 'image/')) {
            $bytes = $this->reencode($bytes, $mime);
        }

        $name = trim((string) ($title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));

        return $this->store($classroom, $by, $bytes, self::TYPES[$mime], mb_substr($name !== '' ? $name : 'Shared file', 0, 200));
    }

    /** A whiteboard page saved as an image (PNG from the browser's canvas, re-encoded here). */
    public function storeBoardImage(Classroom $classroom, User $by, UploadedFile $file, int $page): LessonResource
    {
        if ($file->getSize() > (int) config('classroom.board.snapshot_max_kb') * 1024) {
            throw new ClassroomException('file_too_large', 'That whiteboard image is too large.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png') {
            throw new ClassroomException('file_type', 'The whiteboard image must be a PNG.');
        }

        $when = $classroom->session->starts_at->copy()->timezone(config('app.timezone'))->format('j M Y');

        return $this->store($classroom, $by, $this->reencode($bytes, 'image/png'), 'png', "Whiteboard page {$page}, {$when}");
    }

    /** @return list<array<string, mixed>> */
    public function list(Classroom $classroom): array
    {
        return LessonResource::query()->with('uploader:id,name')->where('session_id', $classroom->tutor_session_id)->orderBy('id')->get()
            ->map(fn (LessonResource $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'type' => $r->file_type,
                'by' => $r->uploader?->name,
                'url' => route('classroom.files.show', [$classroom->tutor_session_id, $r->id]),
                'at' => $r->created_at?->toIso8601String(),
            ])->all();
    }

    public function stream(LessonResource $resource): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($resource->file_path), 404);
        $ext = $resource->file_type ?: 'bin';
        $mime = array_search($ext, self::TYPES, true) ?: 'application/octet-stream';

        return $disk->download($resource->file_path, Str::slug($resource->title ?: 'file').'.'.$ext, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function store(Classroom $classroom, User $by, string $bytes, string $ext, string $title): LessonResource
    {
        $path = 'classroom/'.$classroom->tutor_session_id.'/'.Str::random(40).'.'.$ext;
        Storage::disk(self::DISK)->put($path, $bytes);

        return LessonResource::query()->create([
            'session_id' => $classroom->tutor_session_id,
            'uploader_id' => $by->id,
            'title' => $title,
            'file_path' => $path,
            'file_type' => $ext,
        ]);
    }

    private function reencode(string $bytes, string $mime): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ClassroomException('file_type', 'That image could not be read.');
        }
        if (imagesx($image) * imagesy($image) > 40_000_000) {
            throw new ClassroomException('file_too_large', 'That image is too large.');
        }
        ob_start();
        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imagejpeg($image, null, 85);
        }

        return (string) ob_get_clean();
    }
}
