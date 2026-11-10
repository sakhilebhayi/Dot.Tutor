<?php

namespace App\Domain\Learning\Enums;

/** How a course decides a learner has completed it (null on the course means no certificate). */
enum CompletionRule: string
{
    case AllLessons = 'all_lessons';
    case AllLessonsAndQuizzes = 'all_lessons_and_quizzes';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
