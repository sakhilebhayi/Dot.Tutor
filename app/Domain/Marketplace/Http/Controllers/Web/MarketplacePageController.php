<?php

namespace App\Domain\Marketplace\Http\Controllers\Web;

use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\BookingPresenter;
use App\Domain\Marketplace\Services\MatchingService;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Services\VerificationService;
use App\Domain\Marketplace\Support\LearningNeed;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Minimal server-rendered pages for the marketplace journeys. Functional
 * only: the wave-2 student, tutor and ops UI agents build the real screens on
 * the ds agent's design system, against the same services and JSON API.
 */
class MarketplacePageController extends Controller
{
    public function find(Request $request, MatchingService $matching): View
    {
        $subjects = Subject::active()->orderBy('name')->get();
        $result = null;

        if ($request->filled('subject_id')) {
            // Top-level keys only: only('languages.*') would nest the list a level deeper (R-47 student UI fix, D-1117).
            $keys = array_values(array_filter(array_keys(LearningNeed::rules()), fn (string $key) => ! str_contains($key, '*')));
            $input = array_filter($request->only($keys), fn ($v) => $v !== null && $v !== '' && $v !== []);
            if (isset($input['budget_per_hour'])) {
                unset($input['budget_per_hour']);
            }
            if ($request->filled('budget_rand')) {
                $input['budget_per_hour_minor'] = (int) round(((float) $request->input('budget_rand')) * 100);
            }
            foreach (['languages', 'weekdays'] as $list) {
                if (isset($input[$list]) && is_string($input[$list])) {
                    $input[$list] = array_values(array_filter(array_map('trim', explode(',', $input[$list]))));
                }
            }
            if (isset($input['bilingual_ok'])) {
                $input['bilingual_ok'] = filter_var($input['bilingual_ok'], FILTER_VALIDATE_BOOL);
            }
            $validator = Validator::make($input, LearningNeed::rules());
            if ($validator->passes()) {
                $result = $matching->match(LearningNeed::fromArray($validator->validated()), $request->user());
            } else {
                return view('marketplace.find', ['subjects' => $subjects, 'result' => null, 'helpTypes' => OfferingType::cases()])->withErrors($validator);
            }
        }

        return view('marketplace.find', ['subjects' => $subjects, 'result' => $result, 'helpTypes' => OfferingType::cases()]);
    }

    public function storefront(TutorProfile $tutorProfile, TutorPresenter $presenter): View
    {
        abort_unless($tutorProfile->isApproved(), 404);

        return view('marketplace.storefront', ['tutor' => $presenter->storefront($tutorProfile), 'profile' => $tutorProfile]);
    }

    public function book(Request $request, TutorProfile $tutorProfile, TutorOffering $offering, BookingFlow $flow): RedirectResponse
    {
        abort_unless($offering->tutor_profile_id === $tutorProfile->id, 404);
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'delivery' => ['nullable', 'in:online,in_person'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        [$session, $notice] = $flow->requestBooking($request->user(), $offering, $data);

        return redirect()->route('marketplace.bookings')
            ->with('status', $notice ?? 'Request sent. '.app(BookingPresenter::class)->nextStep($session));
    }

    public function bookings(Request $request, BookingPresenter $presenter): View
    {
        $user = $request->user();
        $profileId = $user->tutorProfile()->value('id');

        return view('marketplace.bookings', [
            // Eager loads match what BookingPresenter::session() reads (one query per relation, not per row: D-1603).
            'asLearner' => TutorSession::query()->with(['tutorProfile.user', 'subject', 'offering', 'events'])->where('student_id', $user->id)->orderByDesc('starts_at')->limit(50)->get()->map(fn ($s) => $presenter->session($s, $user)),
            'asTutor' => $profileId ? TutorSession::query()->with(['tutorProfile.user', 'subject', 'offering', 'events'])->where('tutor_profile_id', $profileId)->orderByDesc('starts_at')->limit(50)->get()->map(fn ($s) => $presenter->session($s, $user)) : collect(),
        ]);
    }

    public function act(Request $request, TutorSession $tutorSession, string $action, BookingFlow $flow): RedirectResponse
    {
        $user = $request->user();
        match ($action) {
            'accept' => $flow->accept($tutorSession, $user),
            'decline' => $flow->decline($tutorSession, $user, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']])['reason']),
            'cancel' => $flow->cancel($tutorSession, $user, $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null),
        };

        return redirect()->route('marketplace.bookings')->with('status', 'Booking updated.');
    }

    public function verification(TutorPresenter $presenter): View
    {
        $queue = TutorQualification::query()
            ->with(['evidence', 'tutorProfile.user'])
            ->where('status', QualificationStatus::Submitted->value)
            ->orderBy('submitted_at')->orderBy('id')
            ->limit(100)->get();

        return view('marketplace.operator-verification', ['queue' => $queue, 'presenter' => $presenter]);
    }

    public function decide(Request $request, TutorQualification $qualification, string $decision, VerificationService $verification): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000']])['note'];
        $decision === 'verify'
            ? $verification->verifyQualification($qualification, $request->user(), $note)
            : $verification->rejectQualification($qualification, $request->user(), $note);

        return redirect()->route('marketplace.operator.verification')->with('status', $decision === 'verify' ? 'Qualification verified.' : 'Qualification rejected.');
    }
}
