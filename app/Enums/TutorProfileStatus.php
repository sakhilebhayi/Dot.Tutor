<?php

namespace App\Enums;

/**
 * Lifecycle of a tutor application. Pending -> Approved | Rejected by an
 * operator; Approved <-> Suspended by an operator; Rejected or Suspended ->
 * Pending when the tutor edits and re-applies.
 */
enum TutorProfileStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
