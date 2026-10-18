<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LessonResource extends Model
{
    protected $fillable = ['session_id', 'uploader_id', 'title', 'file_path', 'file_type'];

    protected static function booted(): void
    {
        // The bytes live on a private disk, outside the database. Whenever the
        // row goes (a Remove click, or its session being deleted), the file goes
        // too -- after commit, so a rolled-back delete never loses a file.
        static::deleted(function (LessonResource $resource) {
            $path = $resource->file_path;

            DB::afterCommit(fn () => Storage::disk('lesson-resources')->delete($path));
        });
    }

    /** @return BelongsTo<TutorSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TutorSession::class, 'session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
