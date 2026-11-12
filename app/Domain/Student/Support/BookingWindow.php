<?php

namespace App\Domain\Student\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The times a learner asked for, carried into booking (fix round D-2304, critic A learner/11, young/34). The find page
 * passes its window on (weekdays, from_time, to_time, the same keys as the search); without one, a young learner
 * starts on after-school times (weekdays 14:00 to 18:00, the window NeedReader reads from "after school"). The window
 * only decides which times are shown first: "Show all times" lists every open time, and the server books any open
 * time either way.
 */
final class BookingWindow
{
    /** @param list<int> $weekdays ISO-8601 (1 Monday .. 7 Sunday); empty = any day */
    private function __construct(
        public readonly array $weekdays,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $source,
    ) {}

    public static function fromRequest(Request $request, bool $young): ?self
    {
        $weekdays = $request->query('weekdays', []);
        if (is_string($weekdays)) {
            $weekdays = explode(',', $weekdays);
        }
        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', is_array($weekdays) ? $weekdays : []),
            fn (int $d) => $d >= 1 && $d <= 7,
        )));
        sort($weekdays);

        $from = self::clock($request->query('from_time'));
        $to = self::clock($request->query('to_time'));
        if ($from !== null && $to !== null && $to <= $from) {
            $to = null;
        }

        if ($weekdays !== [] || $from !== null || $to !== null) {
            return new self($weekdays, $from, $to, 'search');
        }

        return $young ? new self([1, 2, 3, 4, 5], '14:00', '18:00', 'after_school') : null;
    }

    private static function clock(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }

    /** Whether a lesson starting at this local time is inside the window (start from "from" up to, not at, "to"). */
    public function contains(CarbonInterface $local): bool
    {
        if ($this->weekdays !== [] && ! in_array((int) $local->isoWeekday(), $this->weekdays, true)) {
            return false;
        }
        $clock = $local->format('H:i');

        return ($this->from === null || $clock >= $this->from) && ($this->to === null || $clock < $this->to);
    }

    /** "Weekdays 14:00 to 18:00", "Saturdays and Sundays", "Mon, Wed from 15:00". */
    public function label(): string
    {
        $days = match ($this->weekdays) {
            [] => null,
            [1, 2, 3, 4, 5] => 'Weekdays',
            [6, 7] => 'Saturdays and Sundays',
            [1, 2, 3, 4, 5, 6, 7] => 'Every day',
            default => implode(', ', array_map(fn (int $d) => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$d], $this->weekdays)),
        };
        $times = match (true) {
            $this->from !== null && $this->to !== null => $this->from.' to '.$this->to,
            $this->from !== null => 'from '.$this->from,
            $this->to !== null => 'before '.$this->to,
            default => null,
        };

        return ucfirst(trim(($days ?? '').' '.($times ?? '')));
    }

    /** @return array<string, mixed> the query keys the find page uses, to carry the window on to another page */
    public function query(): array
    {
        return array_filter([
            'weekdays' => $this->weekdays === [] ? null : implode(',', $this->weekdays),
            'from_time' => $this->from,
            'to_time' => $this->to,
        ], fn ($v) => $v !== null);
    }

    /** Morning (before 12:00), Afternoon (12:00 to 16:59) or Evening (17:00 on), for grouping a day's times. */
    public static function period(CarbonInterface $local): string
    {
        $hour = (int) $local->format('G');

        return match (true) {
            $hour < 12 => 'Morning',
            $hour < 17 => 'Afternoon',
            default => 'Evening',
        };
    }
}
