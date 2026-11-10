<?php

namespace App\Domain\Safety\Enums;

enum ReportPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /** Sort weight: lower comes first in the moderation queue. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }

    public function responseHours(): int
    {
        return (int) config('safety.reports.response_hours.'.$this->value, 24);
    }
}
