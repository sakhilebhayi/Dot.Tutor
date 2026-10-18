<?php

namespace App\Enums;

/**
 * Every status a tutor_sessions row can hold. The column is a plain string
 * (it was widened from a native enum in 2026-08), so this is the only place
 * the vocabulary is declared.
 */
enum SessionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * Statuses that occupy the tutor's and the student's calendar: a pending
     * request holds the slot so two students cannot ask for the same hour.
     *
     * @return list<string>
     */
    public static function holdingTime(): array
    {
        return [self::Pending->value, self::Confirmed->value];
    }
}
