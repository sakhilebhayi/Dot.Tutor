<?php

namespace App\Domain\Learning\Enums;

/** Course quizzes count toward completion rules; tutor assessments are assigned; practice never counts as an assessed result. */
enum AssessmentKind: string
{
    case CourseQuiz = 'course_quiz';
    case TutorAssessment = 'tutor_assessment';
    case Practice = 'practice';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
