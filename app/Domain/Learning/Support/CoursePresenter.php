<?php

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\CourseVersion;

/** Shapes courses for the API and the views. Never includes storage paths or answer keys. */
class CoursePresenter
{
    public function __construct(private AcademicScopeLookup $academic) {}

    /** @return array<string, mixed> the public course page, built from the published version */
    public function catalogue(Course $course): array
    {
        $version = $course->currentVersion;
        $reviews = $course->reviews()->where('status', 'published');

        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $version?->snapshot['course']['title'] ?? $course->title,
            'description' => $version?->snapshot['course']['description'] ?? $course->description,
            'tutor' => ['id' => $course->owner_id, 'name' => $course->owner?->name ?? 'Deleted account'],
            'academic' => $this->academic->describe($course->curriculum_id, $course->level_id, $course->subject_id),
            'language' => $version?->snapshot['course']['original_locale'] ?? $course->original_locale,
            'objectives' => $version?->snapshot['course']['objectives'] ?? $course->objectives ?? [],
            'prerequisites' => $version?->snapshot['course']['prerequisites'] ?? $course->prerequisites ?? [],
            'workload_minutes' => $version?->snapshot['course']['workload_minutes'] ?? $course->workload_minutes,
            'pricing' => $course->pricing,
            'price_minor' => $course->priceMinor(),
            'currency' => $course->currency,
            'refund_policy' => $course->isFree() ? null : $course->refund_policy,
            'certificate' => ($version?->snapshot['course']['completion_rule'] ?? null) !== null,
            'status' => $course->status->value,
            'version' => $version?->number,
            'outline' => $version ? $this->outline($version) : [],
            'reviews' => ['count' => (clone $reviews)->count(), 'average' => ($avg = (clone $reviews)->avg('rating')) ? round((float) $avg, 1) : null],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function outline(CourseVersion $version): array
    {
        return array_map(fn ($section) => [
            'id' => $section['id'],
            'title' => $section['title'],
            'lessons' => array_map(fn ($lesson) => [
                'id' => $lesson['id'],
                'title' => $lesson['title'],
                'type' => $lesson['type'],
                'duration_minutes' => $lesson['duration_minutes'],
                'is_preview' => $lesson['is_preview'],
            ], $section['lessons']),
        ], $version->snapshot['sections'] ?? []);
    }

    /** @return array<string, mixed> the creator's draft view */
    public function draft(Course $course): array
    {
        $course->loadMissing('sections.lessons', 'materials', 'versions');

        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'subject_id' => $course->subject_id,
            'curriculum_id' => $course->curriculum_id,
            'level_id' => $course->level_id,
            'academic' => $this->academic->describe($course->curriculum_id, $course->level_id, $course->subject_id),
            'original_locale' => $course->original_locale,
            'objectives' => $course->objectives ?? [],
            'prerequisites' => $course->prerequisites ?? [],
            'workload_minutes' => $course->workload_minutes,
            'pricing' => $course->pricing,
            'price_minor' => $course->price_minor,
            'currency' => $course->currency,
            'refund_policy' => $course->refund_policy,
            'completion_rule' => $course->completion_rule?->value,
            'status' => $course->status->value,
            'versions' => $course->versions->map(fn ($v) => ['id' => $v->id, 'number' => $v->number, 'published_at' => $v->published_at->toIso8601String()])->all(),
            'sections' => $course->sections->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'position' => $section->position,
                'lessons' => $section->lessons->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'type' => $lesson->type->value,
                    'body' => $lesson->body,
                    'external_url' => $lesson->external_url,
                    'has_media' => $lesson->media_path !== null,
                    'media_mime' => $lesson->media_mime,
                    'media_size' => $lesson->media_size,
                    'media_original_name' => $lesson->media_original_name,
                    'assessment_id' => $lesson->assessment_id,
                    'duration_minutes' => $lesson->duration_minutes,
                    'is_preview' => $lesson->is_preview,
                    'position' => $lesson->position,
                ])->all(),
            ])->all(),
            'materials' => $course->materials->map(fn ($m) => [
                'id' => $m->id, 'title' => $m->title, 'lesson_id' => $m->lesson_id, 'mime' => $m->mime, 'size' => $m->size,
                'original_name' => $m->original_name, 'permission' => $m->permission->value,
            ])->all(),
        ];
    }
}
