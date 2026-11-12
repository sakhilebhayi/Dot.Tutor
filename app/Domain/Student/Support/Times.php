<?php

namespace App\Domain\Student\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Times on learner screens are shown in the platform zone (config tutor.timezone, Africa/Johannesburg) and every
 * page that shows times says so once. Learners have no stored zone yet (D-1104).
 */
final class Times
{
    public static function zone(): string
    {
        return (string) config('tutor.timezone', 'Africa/Johannesburg');
    }

    public static function local(CarbonInterface|string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone(self::zone());
    }

    /** "Thu 12 Nov, 16:00" */
    public static function when(CarbonInterface|string|null $at): string
    {
        return $at === null ? '' : self::local($at)->format('D j M, H:i');
    }

    /** "Thursday 12 November" */
    public static function day(CarbonInterface|string $at): string
    {
        return self::local($at)->format('l j F');
    }

    public static function clock(CarbonInterface|string $at): string
    {
        return self::local($at)->format('H:i');
    }

    /** "today", "tomorrow", "in 3 days", "on Mon 16 Nov" */
    public static function relative(CarbonInterface|string $at): string
    {
        $local = self::local($at);
        $today = CarbonImmutable::now(self::zone())->startOfDay();
        $days = (int) $today->diffInDays($local->startOfDay(), false);

        return match (true) {
            $days === 0 => 'today at '.$local->format('H:i'),
            $days === 1 => 'tomorrow at '.$local->format('H:i'),
            $days > 1 && $days < 7 => $local->format('l').' at '.$local->format('H:i'),
            default => 'on '.$local->format('D j M').' at '.$local->format('H:i'),
        };
    }

    public static function zoneNote(): string
    {
        return self::zone() === 'Africa/Johannesburg'
            ? 'Times are South African time (SAST).'
            : 'Times are '.self::zone().' time.';
    }
}
