<?php

namespace App\Domain\Comms\Calendar;

use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * An iCalendar (RFC 5545) file for one lesson, so learners, tutors and guardians can add it to their calendars.
 *
 * Times are written in the lesson's own time zone (the tutor's, the zone the lesson was booked in) with a
 * VTIMEZONE built from PHP's time zone database for the months around the lesson, so a calendar shows the right
 * local time anywhere, including across daylight-saving changes. The UID is stable per lesson and SEQUENCE grows
 * with every recorded change, so re-importing an updated file replaces the old entry; a cancelled lesson is
 * STATUS:CANCELLED. Only booking context goes in: subject, tutor, the learner's first name when the learner is a
 * minor, the place (online or the meeting note) and a link to the booking. No contact details.
 */
final class LessonCalendar
{
    /** @param  callable(User): bool|null  $isMinor */
    public function forSession(TutorSession $session, ?callable $isMinor = null): string
    {
        $session->loadMissing(['tutorProfile.user', 'student', 'subject']);
        $zone = $this->zone($session);
        $start = CarbonImmutable::instance($session->starts_at)->setTimezone($zone);
        $end = $start->addMinutes((int) $session->duration_minutes);
        $tutorName = (string) ($session->tutorProfile?->user?->name ?? 'your tutor');
        $subject = (string) ($session->subject?->name ?? 'Lesson');
        $learner = $session->student;
        $learnerName = $learner === null ? null : (($isMinor !== null && $isMinor($learner)) ? self::firstName($learner->name) : (string) $learner->name);
        $cancelled = $session->status === SessionStatus::Cancelled->value;
        $sequence = $session->events()->count();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'dot-tutor';
        $url = route('marketplace.bookings');

        $summary = $session->isGroup() ? "{$subject} group lesson with {$tutorName}" : "{$subject} lesson with {$tutorName}";
        $description = implode("\n", array_filter([
            $session->isGroup() ? 'Group lesson on Dot.Tutor.' : 'One-to-one lesson on Dot.Tutor.',
            'Tutor: '.$tutorName,
            $learnerName !== null && ! $session->isGroup() ? 'Learner: '.$learnerName : null,
            'Times are in '.$zone.'.',
            'Manage the booking: '.$url,
        ]));
        $location = $session->delivery === 'in_person'
            ? (trim((string) $session->location_note) !== '' ? (string) $session->location_note : 'In person (place to be agreed in Dot.Tutor)')
            : 'Online, through Dot.Tutor';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//InfoDot//Dot.Tutor//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            ...VTimezone::lines($zone, $start->subMonths(6), $end->addMonths(6)),
            'BEGIN:VEVENT',
            'UID:tutor-session-'.$session->id.'@'.$host,
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'SEQUENCE:'.$sequence,
            'DTSTART;TZID='.$zone.':'.$start->format('Ymd\THis'),
            'DTEND;TZID='.$zone.':'.$end->format('Ymd\THis'),
            'SUMMARY:'.self::text(($cancelled ? 'Cancelled: ' : '').$summary),
            'DESCRIPTION:'.self::text($description),
            'LOCATION:'.self::text($location),
            'URL:'.$url,
            'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
        ];

        if (! $cancelled) {
            $lines = [...$lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.self::text($summary), 'TRIGGER:-PT30M', 'END:VALARM'];
        }

        $lines = [...$lines, 'END:VEVENT', 'END:VCALENDAR'];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    public function filename(TutorSession $session): string
    {
        return 'dot-tutor-lesson-'.$session->id.'.ics';
    }

    private function zone(TutorSession $session): string
    {
        $candidates = [$session->series?->timezone ?? null, $session->tutorProfile?->timezone, config('tutor.timezone'), 'UTC'];

        foreach ($candidates as $zone) {
            if (is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                return $zone;
            }
        }

        return 'UTC';
    }

    public static function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0] ?? '');

        return $first !== '' ? $first : 'the learner';
    }

    /** RFC 5545 TEXT escaping. */
    public static function text(string $value): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Fold lines longer than 75 octets (continuation lines start with a space), never inside a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            $limit = $out === [] ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
