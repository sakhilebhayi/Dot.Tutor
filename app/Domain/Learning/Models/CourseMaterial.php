<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\MaterialPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseMaterial extends Model
{
    protected $table = 'learning_materials';

    protected $fillable = ['course_id', 'lesson_id', 'title', 'path', 'mime', 'size', 'original_name', 'permission'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['permission' => MaterialPermission::class, 'size' => 'integer'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
