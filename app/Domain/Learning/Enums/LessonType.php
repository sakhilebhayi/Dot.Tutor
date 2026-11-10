<?php

namespace App\Domain\Learning\Enums;

/** What a lesson delivers. Each type has its own publish check (CoursePublisher). */
enum LessonType: string
{
    case Text = 'text';
    case Video = 'video';
    case VideoUrl = 'video_url';
    case Document = 'document';
    case Image = 'image';
    case Slides = 'slides';
    case Practice = 'practice';
    case Quiz = 'quiz';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** Types whose content is an uploaded file on the private disk. */
    public function usesUpload(): bool
    {
        return in_array($this, [self::Video, self::Document, self::Image, self::Slides], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Reading',
            self::Video => 'Video',
            self::VideoUrl => 'External video',
            self::Document => 'Document',
            self::Image => 'Image',
            self::Slides => 'Slides',
            self::Practice => 'Practice activity',
            self::Quiz => 'Quiz',
        };
    }
}
