<?php

namespace App\Domain\Comms\Calendar;

use DateTimeInterface;
use DateTimeZone;

/**
 * A VTIMEZONE component (RFC 5545 section 3.6.5) for one zone over a date range, built from PHP's time zone
 * database: one non-recurring STANDARD or DAYLIGHT observance per transition in the range, plus the observance in
 * force at its start. A zone without transitions (Africa/Johannesburg) gets one STANDARD observance.
 */
final class VTimezone
{
    /** @return list<string> */
    public static function lines(string $zone, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $tz = new DateTimeZone($zone);
        $transitions = $tz->getTransitions($from->getTimestamp(), $to->getTimestamp()) ?: [];

        // The first element is the state at $from itself.
        $initial = $transitions[0] ?? ['offset' => $tz->getOffset(new \DateTimeImmutable('@'.$from->getTimestamp())), 'isdst' => false, 'abbr' => 'UTC'];
        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$zone];
        $lines = [...$lines, ...self::observance((bool) $initial['isdst'], '19700101T000000', (int) $initial['offset'], (int) $initial['offset'], (string) $initial['abbr'])];

        $previous = (int) $initial['offset'];
        foreach (array_slice($transitions, 1) as $t) {
            $offset = (int) $t['offset'];
            // DTSTART is the local time of the change in the observance that is ending (TZOFFSETFROM).
            $local = gmdate('Ymd\THis', (int) $t['ts'] + $previous);
            $lines = [...$lines, ...self::observance((bool) $t['isdst'], $local, $previous, $offset, (string) $t['abbr'])];
            $previous = $offset;
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /** @return list<string> */
    private static function observance(bool $dst, string $start, int $from, int $to, string $abbr): array
    {
        $kind = $dst ? 'DAYLIGHT' : 'STANDARD';

        return [
            'BEGIN:'.$kind,
            'DTSTART:'.$start,
            'TZOFFSETFROM:'.self::offset($from),
            'TZOFFSETTO:'.self::offset($to),
            'TZNAME:'.(preg_match('/^[A-Za-z]+$/', $abbr) ? $abbr : 'UTC'.self::offset($to)),
            'END:'.$kind,
        ];
    }

    public static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return $sign.sprintf('%02d%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
