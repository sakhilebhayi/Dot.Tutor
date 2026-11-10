<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\LessonType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $table = 'learning_lessons';

    protected $fillable = [
        'course_id', 'section_id', 'title', 'type', 'body', 'external_url', 'media_path', 'media_mime', 'media_size',
        'media_original_name', 'assessment_id', 'duration_minutes', 'is_preview', 'position',
    ];

    protected $hidden = ['media_path'];

    protected function casts(): array
    {
        return [
            'type' => LessonType::class,
            'is_preview' => 'boolean',
            'media_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** @return BelongsTo<CourseSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }
}
