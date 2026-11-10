<?php

namespace App\Domain\Community\Services;

use App\Domain\Community\Models\StudySession;

/** An iCalendar (RFC 5545) file for one study session or workshop, so it lands in the person's own calendar. */
class Calendar
{
    public function ics(StudySession $session, string $url): string
    {
        $esc = fn (?string $v) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $v);
        $fmt = fn ($t) => $t->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Dot.Tutor//Community//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$session->uid.'@dot-tutor',
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($session->starts_at),
            'DTEND:'.$fmt($session->endsAt()),
            'SUMMARY:'.$esc($session->title),
            'DESCRIPTION:'.$esc(trim(($session->where_note ? 'Where: '.$session->where_note."\n" : '').$url)),
            'URL:'.$esc($url),
            'STATUS:'.($session->cancelled_at ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";
    }

    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
