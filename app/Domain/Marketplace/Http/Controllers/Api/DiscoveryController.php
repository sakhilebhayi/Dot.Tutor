<?php

namespace App\Domain\Marketplace\Http\Controllers\Api;

use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Services\MatchingService;
use App\Domain\Marketplace\Services\PrivateFiles;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Support\LearningNeed;
use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finding tutors: a browsable directory (fair daily rotation, never ordered
 * by popularity or payment), structured matching with reasons, and each
 * tutor's storefront, open times, offerings, group lessons and reviews.
 */
class DiscoveryController extends Controller
{
    public function index(Request $request, TutorPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'subject_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['online', 'in_person'])],
            'verification_level' => ['nullable', Rule::in(VerificationLevel::values())],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = TutorProfile::query()
            ->with('user')
            ->where('status', TutorProfileStatus::Approved->value)
            ->when($data['subject_id'] ?? null, fn ($q, $id) => $q->whereHas('subjects', fn ($s) => $s->where('subjects.id', $id)->where('subjects.is_active', true)))
            ->when(($data['format'] ?? null) === 'online', fn ($q) => $q->where('teaches_online', true))
            ->when(($data['format'] ?? null) === 'in_person', fn ($q) => $q->where('teaches_in_person', true))
            ->when($data['verification_level'] ?? null, fn ($q, $level) => $q->where('verification_level', $level))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('headline', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))));

        $total = (clone $query)->count();
        $perPage = 20;
        $page = (int) ($data['page'] ?? 1);

        // Fair rotation: a daily-seeded order, so no tutor is always first and none is always last.
        $seed = now()->toDateString();
        $profiles = $query->get()
            ->sortBy(fn (TutorProfile $p) => hash('sha256', $seed.'|'.$p->id))
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        return response()->json([
            'data' => $profiles->map(fn (TutorProfile $p) => [
                'id' => $p->id,
                'name' => $p->displayName(),
                'headline' => $p->headline,
                'verification' => $presenter->verification($p),
                'teaches_online' => (bool) $p->teaches_online,
                'teaches_in_person' => (bool) $p->teaches_in_person,
                'in_person_area' => $p->teaches_in_person ? $p->in_person_area : null,
                'url' => route('marketplace.api.tutors.show', $p),
            ])->all(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'order' => 'daily_rotation'],
        ]);
    }

    public function match(Request $request, MatchingService $matching): JsonResponse
    {
        $need = LearningNeed::fromArray($request->validate(LearningNeed::rules()));

        return response()->json($matching->match($need, $request->user()));
    }

    public function show(TutorProfile $tutorProfile, TutorPresenter $presenter): JsonResponse
    {
        abort_unless($tutorProfile->isApproved(), 404);

        return response()->json(['data' => $presenter->storefront($tutorProfile)]);
    }

    public function availability(Request $request, TutorProfile $tutorProfile, SlotFinder $slots): JsonResponse
    {
        abort_unless($tutorProfile->isApproved(), 404);
        $data = $request->validate([
            'duration' => ['nullable', 'integer', 'min:15', 'max:'.(int) config('marketplace.max_duration_minutes'), 'multiple_of:15'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('tutor.booking_horizon_days')],
        ]);
        $duration = (int) ($data['duration'] ?? 60);

        $days = $slots->forTutor($tutorProfile, $duration, null, isset($data['days']) ? (int) $data['days'] : null);

        return response()->json([
            'timezone' => $tutorProfile->scheduleTimezone(),
            'duration_minutes' => $duration,
            'days' => array_map(fn ($d) => [
                'date' => $d['date'],
                'slots' => array_map(fn ($slot) => $slot->utc()->toIso8601String(), $d['slots']),
            ], $days),
        ]);
    }

    public function offerings(TutorProfile $tutorProfile, TutorPresenter $presenter): JsonResponse
    {
        abort_unless($tutorProfile->isApproved(), 404);

        return response()->json([
            'data' => $tutorProfile->offerings()->where('is_active', true)->with('subjects')->get()
                ->map(fn ($o) => $presenter->offering($o))->all(),
        ]);
    }

    public function groupSessions(TutorProfile $tutorProfile): JsonResponse
    {
        abort_unless($tutorProfile->isApproved(), 404);

        $sessions = TutorSession::query()
            ->with(['subject', 'offering'])
            ->where('tutor_profile_id', $tutorProfile->id)
            ->where('kind', TutorSession::KIND_GROUP)
            ->where('status', SessionStatus::Confirmed->value)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $sessions->map(fn (TutorSession $s) => [
            'id' => $s->id,
            'offering' => $s->offering ? ['id' => $s->offering->id, 'title' => $s->offering->title] : null,
            'subject' => ['id' => $s->subject_id, 'name' => $s->subject?->name],
            'starts_at' => $s->starts_at->copy()->utc()->toIso8601String(),
            'duration_minutes' => $s->duration_minutes,
            'capacity' => $s->capacity,
            'seats_left' => max(0, (int) $s->capacity - (int) $s->seats_taken),
            'price_minor' => $s->price_minor,
            'currency' => $s->currency,
        ])->all(), 'meta' => ['seat_states_counted' => SeatStatus::holding()]]);
    }

    public function reviews(TutorProfile $tutorProfile, TutorPresenter $presenter): JsonResponse
    {
        abort_unless($tutorProfile->isApproved(), 404);

        $reviews = SessionRating::query()
            ->join('tutor_sessions', 'tutor_sessions.id', '=', 'session_ratings.session_id')
            ->where('tutor_sessions.tutor_profile_id', $tutorProfile->id)
            ->where('session_ratings.status', ReviewStatus::Published->value)
            ->orderByDesc('session_ratings.id')
            ->select('session_ratings.*')
            ->with('rater')
            ->paginate(20);

        return response()->json([
            'summary' => $presenter->reviewSummary($tutorProfile),
            'data' => collect($reviews->items())->map(fn (SessionRating $r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'review' => $r->review,
                // First name only: learners may be minors (POPIA minimisation).
                'by' => $r->rater ? strtok((string) $r->rater->name, ' ') : 'Deleted account',
                'verified_booking' => true,
                'tutor_reply' => $r->tutor_reply,
                'tutor_replied_at' => $r->tutor_replied_at?->toIso8601String(),
                'at' => $r->created_at?->toIso8601String(),
            ])->all(),
            'meta' => ['total' => $reviews->total(), 'page' => $reviews->currentPage()],
        ]);
    }

    /** The tutor's photo: private file, streamed to signed-in users while the tutor is approved (or to its owner). */
    public function photo(Request $request, TutorProfile $tutorProfile, PrivateFiles $files): StreamedResponse
    {
        abort_unless($tutorProfile->isApproved() || $tutorProfile->user_id === $request->user()->id, 404);
        abort_unless($files->exists($tutorProfile->photo_path), 404);

        return $files->stream($tutorProfile->photo_path, 'image/jpeg', 'tutor-'.$tutorProfile->id, inline: true);
    }
}
