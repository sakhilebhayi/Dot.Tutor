<?php

namespace App\Domain\Teach\Services;

use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Teach\Models\StudentNote;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The tutor's customer list (brief sections 7 and 18): everyone who booked a lesson, took a group seat, bought a
 * package or enrolled in one of the tutor's courses. Only what the tutor's own business produced is shown; a
 * learner's other tutors, courses and personal details are never read. Minors appear by account name only.
 */
class StudentBook
{
    public function __construct(private ProgressService $progress) {}

    /** @return list<int> learner ids that belong to this tutor's business */
    public function learnerIds(TutorProfile $profile): array
    {
        $fromLessons = TutorSession::query()->where('tutor_profile_id', $profile->id)->whereNotNull('student_id')
            ->whereNotIn('status', ['cancelled', 'awaiting_guardian'])->distinct()->pluck('student_id');
        $fromSeats = TutorSessionParticipant::query()->whereHas('session', fn ($q) => $q->where('tutor_profile_id', $profile->id))
            ->whereNotIn('status', ['cancelled', 'awaiting_guardian'])->distinct()->pluck('learner_id');
        $fromPackages = TutorPackagePurchase::query()->where('tutor_profile_id', $profile->id)
            ->whereNotIn('status', ['cancelled', 'awaiting_guardian'])->distinct()->pluck('learner_id');
        $fromCourses = $profile->user_id ? Enrolment::query()->whereIn('course_id', Course::query()->where('owner_id', $profile->user_id)->select('id'))
            ->distinct()->pluck('user_id') : collect();

        return $fromLessons->merge($fromSeats)->merge($fromPackages)->merge($fromCourses)
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function isStudent(TutorProfile $profile, int $learnerId): bool
    {
        return in_array($learnerId, $this->learnerIds($profile), true);
    }

    /**
     * One row per student with lesson counts, the next and last lesson and course enrolments.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(TutorProfile $profile, ?string $search = null): Collection
    {
        $ids = $this->learnerIds($profile);
        if ($ids === []) {
            return collect();
        }

        $users = User::query()->whereIn('id', $ids)
            ->when($search, fn ($q, $s) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%'))
            ->orderBy('name')->get(['id', 'name']);

        return $this->rows($profile, $users);
    }

    /**
     * One page of the list for the students screen (R-47 D-1606): the counts come from grouped queries and only the
     * page's students are read in detail, so a tutor with thousands of students gets the same page cost as one with ten.
     *
     * @return array{rows: LengthAwarePaginator, total: int, repeat: int}
     */
    public function page(TutorProfile $profile, ?string $search, bool $repeatOnly, int $perPage, int $page, array $options = []): array
    {
        $ids = $this->learnerIds($profile);
        $repeatIds = $ids === [] ? [] : TutorSession::query()->where('tutor_profile_id', $profile->id)->where('status', 'completed')
            ->whereNotNull('student_id')->groupBy('student_id')->havingRaw('count(*) >= 2')->pluck('student_id')
            ->map(fn ($id) => (int) $id)->intersect($ids)->values()->all();
        $base = User::query()->whereIn('id', $ids)
            ->when($search, fn ($q, $s) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%'));

        $total = (clone $base)->count();
        $repeat = $repeatIds === [] ? 0 : (clone $base)->whereIn('id', $repeatIds)->count();
        $users = (clone $base)->when($repeatOnly, fn ($q) => $q->whereIn('id', $repeatIds))
            ->orderBy('name')->orderBy('id')->paginate($perPage, ['id', 'name'], 'page', $page);
        $users->setCollection($this->rows($profile, $users->getCollection()));
        if (isset($options['path'])) {
            $users->withPath($options['path']);
        }
        $users->appends($options['query'] ?? []);

        return ['rows' => $users, 'total' => $total, 'repeat' => $repeat];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(TutorProfile $profile, Collection $users): Collection
    {
        $ids = $users->pluck('id')->all();
        if ($ids === []) {
            return collect();
        }
        $sessions = TutorSession::query()->where('tutor_profile_id', $profile->id)->whereIn('student_id', $ids)->where('status', '!=', 'awaiting_guardian')
            ->get(['id', 'student_id', 'status', 'starts_at'])->groupBy('student_id');
        $courseIds = $profile->user_id ? Course::query()->where('owner_id', $profile->user_id)->pluck('id') : collect();
        $enrolments = Enrolment::query()->whereIn('course_id', $courseIds)->whereIn('user_id', $ids)
            ->get(['id', 'user_id', 'course_id', 'status'])->groupBy('user_id');
        $notes = StudentNote::query()->where('tutor_profile_id', $profile->id)->whereIn('learner_id', $ids)->pluck('learner_id')->flip();
        $now = now();

        return $users->map(function (User $u) use ($sessions, $enrolments, $notes, $now) {
            $mine = $sessions->get($u->id, collect());

            return [
                'id' => $u->id,
                'name' => $u->name,
                'completed' => $mine->where('status', 'completed')->count(),
                'upcoming' => $mine->whereIn('status', ['confirmed', 'awaiting_payment', 'pending'])->filter(fn ($s) => $s->starts_at->greaterThan($now))->count(),
                'next' => $mine->whereIn('status', ['confirmed', 'awaiting_payment'])->filter(fn ($s) => $s->starts_at->greaterThan($now))->sortBy('starts_at')->first()?->starts_at,
                'last' => $mine->where('status', 'completed')->sortByDesc('starts_at')->first()?->starts_at,
                'courses' => $enrolments->get($u->id, collect())->count(),
                'has_note' => $notes->has($u->id),
                'repeat' => $mine->where('status', 'completed')->count() >= 2,
            ];
        })->values();
    }

    /** @return array<string, mixed> */
    public function detail(TutorProfile $profile, User $learner): array
    {
        $courseIds = $profile->user_id ? Course::query()->where('owner_id', $profile->user_id)->pluck('id') : collect();
        $assessmentIds = $profile->user_id ? Assessment::query()->where('owner_id', $profile->user_id)->pluck('id') : collect();

        return [
            'sessions' => TutorSession::query()->where('tutor_profile_id', $profile->id)->where('student_id', $learner->id)->where('status', '!=', 'awaiting_guardian')
                ->with(['subject:id,name', 'offering:id,title'])->orderByDesc('starts_at')->limit(50)->get(),
            'packages' => TutorPackagePurchase::query()->where('tutor_profile_id', $profile->id)->where('learner_id', $learner->id)
                ->with('offering:id,title')->orderByDesc('id')->get(),
            'courses' => Enrolment::query()->whereIn('course_id', $courseIds)->where('user_id', $learner->id)
                ->with(['course:id,title', 'version'])->get()
                ->map(fn (Enrolment $e) => ['title' => $e->course?->title, 'status' => $e->status, 'summary' => $this->progress->summary($e)]),
            'assignments' => Assignment::query()->whereIn('assessment_id', $assessmentIds)->where('learner_id', $learner->id)
                ->with('assessment:id,title,pass_percent')->orderByDesc('id')->get()
                ->map(function (Assignment $a) use ($learner) {
                    $best = Attempt::query()->where('assessment_id', $a->assessment_id)->where('user_id', $learner->id)
                        ->whereNotNull('submitted_at')->orderByDesc('percent')->first();

                    return ['title' => $a->assessment?->title, 'due_at' => $a->due_at, 'best' => $best?->percent, 'passed' => $best?->passed, 'attempted' => $best !== null];
                }),
            'note' => StudentNote::query()->where('tutor_profile_id', $profile->id)->where('learner_id', $learner->id)->first(),
        ];
    }
}
