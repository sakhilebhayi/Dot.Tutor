<?php

namespace App\Http\Controllers;

use App\Models\TutorProfile;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A tutor's weekly recurring hours and blocked dates. Times are wall-clock
 * in the timezone on the tutor's own profile.
 */
class TutorAvailabilityController extends Controller
{
    public function index(Request $request, SlotFinder $slots): RedirectResponse|View
    {
        $profile = $request->user()->tutorProfile()->first();

        if (! $profile) {
            return redirect()->route('tutor.profile.edit')
                ->with('status', 'Apply to teach first. You set your hours once you have a profile.');
        }

        $tz = $profile->scheduleTimezone();
        $today = Carbon::now($tz)->toDateString();

        $days = $slots->forTutor($profile, 60);

        return view('tutor.availability.index', [
            'profile' => $profile,
            'windows' => $profile->availabilityWindows()->get()->groupBy('weekday'),
            'blockedDates' => $profile->blockedDates()->where('date', '>=', $today)->get(),
            'openDaysAhead' => collect($days)->filter(fn ($d) => count($d['slots']) > 0)->count(),
            'timezone' => $tz,
            'today' => $today,
        ]);
    }

    public function storeWindow(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ]);

        // Zero-padded 24h strings compare correctly as text.
        if ($data['end_time'] <= $data['start_time']) {
            throw ValidationException::withMessages(['end_time' => 'The end must be after the start. Hours cannot run past midnight; add a second window on the next day instead.']);
        }

        $clash = $profile->availabilityWindows()
            ->where('weekday', $data['weekday'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'start_time' => "That overlaps your {$clash->start_time}-{$clash->end_time} window on the same day.",
            ]);
        }

        $profile->availabilityWindows()->create($data);

        return redirect()->route('tutor.availability.index')->with('status', 'Hours added.');
    }

    public function destroyWindow(Request $request, int $window): RedirectResponse
    {
        $this->profile($request)->availabilityWindows()->whereKey($window)->firstOrFail()->delete();

        return redirect()->route('tutor.availability.index')->with('status', 'Hours removed.');
    }

    public function storeBlockedDate(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['date'] < Carbon::now($profile->scheduleTimezone())->toDateString()) {
            throw ValidationException::withMessages(['date' => 'That date has already passed.']);
        }
        if ($profile->blockedDates()->where('date', $data['date'])->exists()) {
            throw ValidationException::withMessages(['date' => 'That date is already blocked.']);
        }

        $profile->blockedDates()->create($data);

        return redirect()->route('tutor.availability.index')->with('status', 'Date blocked.');
    }

    public function destroyBlockedDate(Request $request, int $blockedDate): RedirectResponse
    {
        $this->profile($request)->blockedDates()->whereKey($blockedDate)->firstOrFail()->delete();

        return redirect()->route('tutor.availability.index')->with('status', 'Date unblocked.');
    }

    private function profile(Request $request): TutorProfile
    {
        return $request->user()->tutorProfile()->first() ?? abort(404);
    }
}
