<?php

namespace App\Domain\Learning\Enums;

/** Kinds of library material (brief section 9). */
enum ResourceType: string
{
    case Notes = 'notes';
    case RevisionGuide = 'revision_guide';
    case PastPaper = 'past_paper';
    case Summary = 'summary';
    case Flashcards = 'flashcards';
    case Presentation = 'presentation';
    case Worksheet = 'worksheet';
    case PracticeQuestions = 'practice_questions';
    case Video = 'video';
    case Diagram = 'diagram';
    case Reference = 'reference';
    case LessonPlan = 'lesson_plan';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
