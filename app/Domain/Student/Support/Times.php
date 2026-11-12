<?php

namespace App\Domain\Student\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Every time a learner reads is shown in the reader's own zone (ViewerZones: their notification settings, else
 * their tutor profile, else the platform zone, config tutor.timezone) and the zone is named: once per page with
 * zoneNote(), or on the time itself with stamp() where the time travels alone (an order line, a notice, a
 * hold expiry). Times are stored in UTC; nothing on a learner screen prints a UTC wall clock without saying so
 * (R-47 polish D-2001: the order said 08:00 where the notification said 10:00 SAST for the same lesson).
 */
final class Times
{
    public static function platformZone(): string
    {
        return (string) config('tutor.timezone', 'Africa/Johannesburg');
    }

    /** The zone of the person reading (the signed-in user), or the platform zone for a guest or a background job. */
    public static function zone(?User $viewer = null): string
    {
        $viewer ??= auth()->user();

        return $viewer instanceof User ? app(ViewerZones::class)->for($viewer) : self::platformZone();
    }

    public static function local(CarbonInterface|string $at, ?string $zone = null): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone($zone ?? self::zone());
    }

    /** "Thu 12 Nov, 16:00" (the page names the zone once with zoneNote()). */
    public static function when(CarbonInterface|string|null $at): string
    {
        return $at === null ? '' : self::local($at)->format('D j M, H:i');
    }

    /** "Thu 12 Nov, 16:00 SAST": a time that stands alone names its zone. */
    public static function stamp(CarbonInterface|string|null $at, string $format = 'D j M, H:i', ?string $zone = null): string
    {
        if ($at === null) {
            return '';
        }
        $local = self::local($at, $zone);

        return $local->format($format).' '.self::abbreviation($local);
    }

    /** "SAST", "GMT", "CET"; zones without a letter abbreviation get their name ("UTC+04" becomes "Asia/Dubai"). */
    public static function abbreviation(CarbonInterface $local): string
    {
        $abbr = $local->format('T');

        return preg_match('/^[A-Z]{2,5}$/', $abbr) === 1 ? $abbr : $local->getTimezone()->getName();
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
        $today = CarbonImmutable::now($local->getTimezone())->startOfDay();
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
        $zone = self::zone();
        if ($zone === 'Africa/Johannesburg') {
            return 'Times are South African time (SAST).';
        }

        return 'Times are '.str_replace('_', ' ', $zone).' time ('.self::abbreviation(CarbonImmutable::now($zone)).'). Change your time zone in notification settings.';
    }
}
