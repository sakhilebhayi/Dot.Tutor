<?php

namespace App\Domain\Marketplace\Enums;

/** Only published reviews are public and count toward a tutor's average. */
enum ReviewStatus: string
{
    case Published = 'published';
    case Held = 'held';
    case Removed = 'removed';
}
