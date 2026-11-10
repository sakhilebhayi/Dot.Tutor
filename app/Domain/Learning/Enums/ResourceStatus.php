<?php

namespace App\Domain\Learning\Enums;

/** Visibility of a library resource. */
enum ResourceStatus: string
{
    case Published = 'published';
    case PendingReview = 'pending_review';
    case TakenDown = 'taken_down';
    case Withdrawn = 'withdrawn';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
