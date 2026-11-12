<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable published snapshot of a course's structure and lesson content. Enrolled learners read the version
 * they enrolled on until they choose to move to the newest one, so a creator's edits never break a learner mid-course.
 */
class CourseVersion extends Model
{
    protected $table = 'learning_course_versions';

    protected $fillable = ['course_id', 'number', 'snapshot', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime', 'number' => 'integer'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** @return array<string, mixed>|null */
    public function lesson(int $lessonId): ?array
    {
        foreach ($this->snapshot['sections'] ?? [] as $section) {
            foreach ($section['lessons'] as $lesson) {
                if ((int) $lesson['id'] === $lessonId) {
                    return $lesson;
                }
            }
        }

        return null;
    }

    /** @return list<int> */
    public function lessonIds(): array
    {
        $ids = [];
        foreach ($this->snapshot['sections'] ?? [] as $section) {
            foreach ($section['lessons'] as $lesson) {
                $ids[] = (int) $lesson['id'];
            }
        }

        return $ids;
    }
}
