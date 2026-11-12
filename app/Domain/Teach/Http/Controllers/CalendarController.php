<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Teach\Services\BookingQueries;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TutorAvailabilityController;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The tutor's week: open hours, days off and every lesson, with the weekly hours edited in place. Writes go through
 * the existing availability controller (its overlap and past-date rules), so there is one set of rules.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, BookingQueries $bookings): View
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);
        $requested = $request->query('week');
        $anchor = is_string($requested) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)
            ? CarbonImmutable::createFromFormat('Y-m-d', $requested, $tz) : CarbonImmutable::now($tz);
        $start = $anchor->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $end = $start->addDays(7);

        $lessons = $bookings->between($profile, $start, $end, BookingQueries::ON_CALENDAR)
            ->groupBy(fn ($s) => $s->starts_at->copy()->timezone($tz)->format('Y-m-d'));
        $windows = $profile->availabilityWindows()->get()->groupBy('weekday');
        $blocked = $profile->blockedDates()->get()->keyBy(fn ($b) => $b->date instanceof \DateTimeInterface ? $b->date->format('Y-m-d') : substr((string) $b->date, 0, 10));

        $days = [];
        for ($d = $start; $d->lessThan($end); $d = $d->addDay()) {
            $key = $d->format('Y-m-d');
            $days[] = [
                'date' => $d,
                'key' => $key,
                'today' => $d->isSameDay(CarbonImmutable::now($tz)),
                'windows' => $windows->get($d->dayOfWeekIso, collect()),
                'blocked' => $blocked->get($key),
                'lessons' => $lessons->get($key, collect()),
            ];
        }

        return view('teach.calendar', [
            'profile' => $profile,
            'tz' => $tz,
            'days' => $days,
            'start' => $start,
            'prev' => $start->subWeek()->format('Y-m-d'),
            'next' => $start->addWeek()->format('Y-m-d'),
            'allWindows' => $profile->availabilityWindows()->get(),
            'upcomingBlocked' => $profile->blockedDates()->where('date', '>=', CarbonImmutable::now($tz)->toDateString())->get(),
        ]);
    }

    public function storeWindow(Request $request, TutorAvailabilityController $availability): RedirectResponse
    {
        $availability->storeWindow($request);

        return back()->with('success', 'Weekly hours added.');
    }

    public function destroyWindow(Request $request, int $window, TutorAvailabilityController $availability): RedirectResponse
    {
        $availability->destroyWindow($request, $window);

        return back()->with('success', 'Weekly hours removed.');
    }

    public function storeBlocked(Request $request, TutorAvailabilityController $availability): RedirectResponse
    {
        $availability->storeBlockedDate($request);

        return back()->with('success', 'Day off added. Learners cannot book it.');
    }

    public function destroyBlocked(Request $request, int $blockedDate, TutorAvailabilityController $availability): RedirectResponse
    {
        $availability->destroyBlockedDate($request, $blockedDate);

        return back()->with('success', 'Day off removed.');
    }
}
