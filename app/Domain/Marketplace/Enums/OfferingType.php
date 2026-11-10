<?php

namespace App\Domain\Marketplace\Enums;

/** Brief sections 6 and 7: every kind of tutoring a learner can book. */
enum OfferingType: string
{
    case OneToOne = 'one_to_one';
    case Group = 'group';
    case Consultation = 'consultation';
    case HomeworkHelp = 'homework_help';
    case ExamPrep = 'exam_prep';
    case Revision = 'revision';
    case Package = 'package';
    case RecurringWeekly = 'recurring_weekly';

    public function label(): string
    {
        return match ($this) {
            self::OneToOne => 'One-to-one lesson',
            self::Group => 'Group lesson',
            self::Consultation => 'Short consultation',
            self::HomeworkHelp => 'Homework help',
            self::ExamPrep => 'Exam preparation',
            self::Revision => 'Revision session',
            self::Package => 'Lesson package',
            self::RecurringWeekly => 'Weekly lessons',
        };
    }

    /** Group offerings are scheduled by the tutor and learners take seats; the rest are booked by a learner. */
    public function isGroup(): bool
    {
        return $this === self::Group;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
