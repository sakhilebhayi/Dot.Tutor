<?php

namespace App\Domain\Learning\Enums;

/** Lifecycle of a course: drafts are private; published courses appear in the catalogue; archived courses accept no new enrolments but stay readable for enrolled learners. */
enum CourseStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
