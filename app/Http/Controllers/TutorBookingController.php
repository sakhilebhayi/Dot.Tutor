<?php

namespace App\Http\Controllers;

use App\Domain\Marketplace\Services\BookingFlow;
use App\Enums\TutorProfileStatus;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Services\Scheduling\BookingService;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TutorBookingController extends Controller
{
    /**
     * Browse approved tutors, optionally filtered to one (active) subject.
     */
    public function browse(Request $request): View
    {
        $subjects = Subject::active()->orderBy('name')->get();

        $selectedSubjectId = $request->integer('subject');

        $tutors = TutorProfile::query()
            ->with(['user', 'subjects' => fn ($q) => $q->where('subjects.is_active', true)])
            ->where('status', TutorProfileStatus::Approved->value)
            ->when($selectedSubjectId, fn ($query) => $query->whereHas(
                'subjects',
                fn ($q) => $q->where('subjects.id', $selectedSubjectId)->where('subjects.is_active', true),
            ))
            ->orderByDesc('rating')
            ->paginate(12);

        return view('tutors.browse', [
            'tutors' => $tutors,
            'subjects' => $subjects,
            'selectedSubjectId' => $selectedSubjectId,
        ]);
    }

    /**
     * One tutor's public profile, their real open times for the next 14 days,
     * and the booking form.
     */
    public function show(Request $request, TutorProfile $tutorProfile, SlotFinder $slots): View
    {
        abort_unless($tutorProfile->status === TutorProfileStatus::Approved->value, 404);

        $tutorProfile->load(['user', 'subjects' => fn ($q) => $q->where('subjects.is_active', true)]);

        $reviews = $tutorProfile->ratings()
            ->whereNotNull('review')
            ->with('rater')
            ->latest()
            ->limit(20)
            ->get();

        $durations = (array) config('tutor.durations');
        $duration = $request->integer('duration');
        $duration = in_array($duration, $durations, true) ? $duration : (in_array(60, $durations, true) ? 60 : $durations[0]);

        $days = $slots->forTutor($tutorProfile, $duration);

        return view('tutors.show', [
            'tutorProfile' => $tutorProfile,
            'reviews' => $reviews,
            'days' => $days,
            'hasSlots' => collect($days)->contains(fn ($d) => count($d['slots']) > 0),
            'duration' => $duration,
            'durations' => $durations,
            'timezone' => $tutorProfile->scheduleTimezone(),
            'horizonDays' => (int) config('tutor.booking_horizon_days'),
        ]);
    }

    /**
     * Create a session booking. The slot must be one of the tutor's real open
     * times: inside weekly hours, not on a blocked date, and not overlapping
     * another pending/confirmed session of the tutor or of the student. All of
     * that is checked under a row lock in BookingService.
     */
    public function store(Request $request, TutorProfile $tutorProfile, BookingService $booking): RedirectResponse
    {
        abort_unless($tutorProfile->status === TutorProfileStatus::Approved->value, 404);
        abort_if($tutorProfile->user_id === $request->user()->id, 403, 'You cannot book a session with yourself.');

        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'in:'.implode(',', (array) config('tutor.durations'))],
            'delivery' => ['required', 'in:online,in_person'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $session = $booking->book($request->user(), $tutorProfile, $validated);

        return redirect()
            ->route('sessions.show', $session)
            ->with('status', 'Session requested. The tutor will confirm shortly.');
    }

    public function showSession(TutorSession $tutorSession): View
    {
        Gate::authorize('view', $tutorSession);

        $tutorSession->load(['tutorProfile.user', 'subject', 'student', 'rating', 'resources.uploader']);

        return view('sessions.show', [
            'session' => $tutorSession,
        ]);
    }

    public function cancel(Request $request, TutorSession $tutorSession, BookingFlow $flow): RedirectResponse
    {
        Gate::authorize('cancel', $tutorSession);

        // R-47: through the booking state machine (row lock, event, refund terms).
        $flow->cancel($tutorSession, $request->user());

        return redirect()
            ->route('sessions.show', $tutorSession)
            ->with('status', 'Session cancelled.');
    }

    /**
     * The tutor accepts a pending booking request.
     */
    public function confirm(Request $request, TutorSession $tutorSession, BookingFlow $flow): RedirectResponse
    {
        Gate::authorize('confirm', $tutorSession);

        // R-47: a paid v2 booking moves to awaiting payment; a legacy or free one is confirmed.
        $session = $flow->accept($tutorSession, $request->user());

        return redirect()
            ->route('sessions.show', $tutorSession)
            ->with('status', $session->status === 'awaiting_payment' ? 'Accepted. The learner has been asked to pay to confirm it.' : 'Session confirmed.');
    }

    /**
     * Either party marks a confirmed session as having happened. This is
     * what makes leaving a rating/review possible — see SessionRatingController.
     */
    public function complete(Request $request, TutorSession $tutorSession, BookingFlow $flow): RedirectResponse
    {
        Gate::authorize('complete', $tutorSession);

        // Under a row lock in the state machine: two quick clicks (or both
        // parties at once) count the session once, not twice.
        $completed = $flow->complete($tutorSession, $request->user());

        return redirect()
            ->route('sessions.show', $tutorSession)
            ->with('status', $completed ? 'Session marked complete.' : 'That session was already completed.');
    }
}
