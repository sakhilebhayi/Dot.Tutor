<?php

namespace App\Http\Controllers;

use App\Enums\TutorProfileStatus;
use App\Models\Subject;
use App\Models\TutorProfile;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A user's own tutor application / profile: apply once, edit while pending or
 * approved, and re-apply (by editing) after a rejection or suspension.
 */
class TutorProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $profile = $request->user()->tutorProfile()->first();

        return view('tutor.profile.edit', [
            'profile' => $profile,
            'subjects' => Subject::active()->orderBy('name')->get(),
            'selectedSubjectIds' => $profile
                ? $profile->subjects()->pluck('subjects.id')->all()
                : [],
            'currency' => $profile?->currency ?: config('tutor.currency'),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('tutor.timezone'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->tutorProfile()->exists()) {
            return redirect()->route('tutor.profile.edit')
                ->with('status', 'You already have a tutor profile. Edit it here instead of applying again.');
        }

        $data = $this->validated($request);

        try {
            DB::transaction(function () use ($user, $data) {
                $profile = TutorProfile::create([
                    'user_id' => $user->id,
                    'headline' => $data['headline'],
                    'bio' => $data['bio'],
                    'qualifications' => $data['qualifications'],
                    'hourly_rate' => $data['hourly_rate'],
                    'currency' => config('tutor.currency'),
                    'timezone' => $data['timezone'],
                    'status' => TutorProfileStatus::Pending->value,
                ]);
                $profile->subjects()->sync($data['subjects']);
            });
        } catch (UniqueConstraintViolationException) {
            // A second tab won the race; one profile per user is the rule.
            return redirect()->route('tutor.profile.edit');
        }

        return redirect()->route('tutor.profile.edit')
            ->with('status', 'Application sent. An operator will review it and you will be told the decision here.');
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = $request->user()->tutorProfile()->first() ?? abort(404);
        $data = $this->validated($request);

        $reapplying = in_array($profile->status, [TutorProfileStatus::Rejected->value, TutorProfileStatus::Suspended->value], true);

        // Subjects deactivated since the tutor chose them are not on the form,
        // so they are carried over untouched; only the active ones are edited.
        $activeIds = Subject::active()->pluck('id')->all();
        $currentActive = $profile->subjects()->whereIn('subjects.id', $activeIds)->pluck('subjects.id')->all();
        $keptInactive = $profile->subjects()->whereNotIn('subjects.id', $activeIds)->pluck('subjects.id')->all();

        $rateChanged = (float) $profile->hourly_rate !== (float) $data['hourly_rate'];

        $unchanged = ! $rateChanged
            && $profile->headline === $data['headline']
            && $profile->bio === $data['bio']
            && $profile->qualifications === $data['qualifications']
            && $profile->scheduleTimezone() === $data['timezone']
            && $this->sameIds($currentActive, $data['subjects']);

        if ($reapplying && $unchanged) {
            throw ValidationException::withMessages([
                'headline' => 'Change something in response to the reason above before re-applying. The same application would be decided the same way.',
            ]);
        }

        DB::transaction(function () use ($profile, $data, $reapplying, $rateChanged, $keptInactive) {
            $changes = [
                'headline' => $data['headline'],
                'bio' => $data['bio'],
                'qualifications' => $data['qualifications'],
                'hourly_rate' => $data['hourly_rate'],
                'timezone' => $data['timezone'],
            ];

            // The rate the tutor just typed is in the currency the form showed.
            if ($rateChanged) {
                $changes['currency'] = config('tutor.currency');
            }
            if ($reapplying) {
                $changes += [
                    'status' => TutorProfileStatus::Pending->value,
                    'rejected_reason' => null,
                    'suspended_reason' => null,
                ];
            }

            $profile->update($changes);
            $profile->subjects()->sync(array_values(array_unique([...$data['subjects'], ...$keptInactive])));
        });

        return redirect()->route('tutor.profile.edit')
            ->with('status', $reapplying
                ? 'Re-application sent. An operator will review it.'
                : 'Profile saved. Sessions already booked keep the rate they were booked at.');
    }

    /** @return array{headline: string, bio: string, qualifications: string, hourly_rate: string, timezone: string, subjects: list<int>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:160'],
            'bio' => ['required', 'string', 'max:4000'],
            'qualifications' => ['required', 'string', 'max:4000'],
            'hourly_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999.99'],
            'timezone' => ['required', 'timezone:all'],
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => ['integer', 'distinct', Rule::exists('subjects', 'id')->where('is_active', true)],
        ]);

        $data['subjects'] = array_map('intval', $data['subjects']);

        return $data;
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function sameIds(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
