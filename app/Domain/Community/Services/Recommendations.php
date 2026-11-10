<?php

namespace App\Domain\Community\Services;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Community\Models\Room;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Marketplace\Contracts\TutorDirectory;
use App\Domain\Safety\Services\Screening;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Tutors, courses and library material for a room, ranked by relevance to the room's scope and the viewer's
 * languages, never by popularity (no ratings, enrolment counts or views enter the order). Each item carries the
 * reasons it was ranked where it is, met and unmet.
 */
class Recommendations
{
    public function __construct(
        private readonly TutorDirectory $tutors,
        private readonly LanguageProfile $languages,
        private readonly Screening $screening,
    ) {}

    /** @return list<array<string, mixed>> */
    public function tutorsFor(Room $room, User $viewer, int $limit = 6): array
    {
        $wanted = $this->viewerLanguages($viewer);

        $cards = collect($this->tutors->approved(['subject_id' => $room->subject_id, 'level_id' => $room->academic_level_id, 'limit' => 100]))
            ->reject(fn (array $card) => (int) $card['user_id'] === $viewer->id);

        // In a room for learners under 18 only tutors with a current safeguarding screening are suggested: a minor
        // could not book anyone else (the booking gate refuses), so suggesting them would mislead.
        if ($room->isForMinors()) {
            $cards = $cards->filter(fn (array $card) => ($u = User::query()->find($card['user_id'])) && $this->screening->isClearedForMinors($u));
        }

        return $cards->map(function (array $card) use ($room, $wanted) {
            $scopes = collect($card['scopes'] ?? []);
            $exact = $scopes->contains(fn ($s) => (int) ($s['curriculum_id'] ?? 0) === $room->curriculum_id
                && in_array($room->academic_level_id, array_filter([(int) ($s['level_min_id'] ?? 0), (int) ($s['level_max_id'] ?? 0)]), true));
            $levelOpen = $scopes->isEmpty() || $scopes->contains(fn ($s) => empty($s['level_min_id']));
            $codes = collect($card['languages'] ?? [])->map(fn ($l) => is_array($l) ? ($l['code'] ?? null) : $l)->filter()->values();
            $shared = $codes->intersect($wanted)->values();
            $roomLanguage = $room->language !== null && $codes->contains($room->language);
            $verified = ! empty($card['verification']['level']);

            $score = ($exact ? 4 : ($levelOpen ? 1 : 2)) + ($shared->isNotEmpty() ? 2 : 0) + ($roomLanguage ? 1 : 0) + ($verified ? 1 : 0);

            return $card + [
                'score' => $score,
                'url' => Route::has('marketplace.tutors.show') ? route('marketplace.tutors.show', $card['id']) : null,
                'reasons' => [
                    ['text' => 'Teaches '.($room->subject?->name ?? 'this subject'), 'met' => true],
                    ['text' => $exact ? 'Teaches this level in this curriculum' : 'Level range not stated for this curriculum', 'met' => $exact],
                    ['text' => $shared->isNotEmpty() ? 'Teaches in a language you understand ('.$shared->implode(', ').')' : 'No teaching language you listed', 'met' => $shared->isNotEmpty()],
                    ['text' => $verified ? 'Verification: '.($card['verification']['label'] ?? 'reviewed') : 'Not yet verified', 'met' => $verified],
                ],
            ];
        })->sortBy([['score', 'desc'], ['id', 'asc']])->take($limit)->values()->all();
    }

    /** @return Collection<int, array{course: Course, reasons: list<array{text: string, met: bool}>}> */
    public function coursesFor(Room $room, User $viewer, int $limit = 6): Collection
    {
        $wanted = $this->viewerLanguages($viewer);

        return Course::query()->with('owner')
            ->where('status', CourseStatus::Published->value)
            ->where('subject_id', $room->subject_id)
            ->where(fn ($q) => $q->where('level_id', $room->academic_level_id)->orWhereNull('level_id'))
            ->orderBy('id')->limit(100)->get()
            ->map(function (Course $course) use ($room, $wanted) {
                $level = (int) $course->level_id === $room->academic_level_id;
                $curriculum = (int) $course->curriculum_id === $room->curriculum_id;
                $language = $wanted->contains($course->original_locale);

                return [
                    'course' => $course,
                    'score' => ($level ? 3 : 0) + ($curriculum ? 2 : 0) + ($language ? 1 : 0),
                    'reasons' => [
                        ['text' => $level ? 'Made for this level' : 'Level not stated', 'met' => $level],
                        ['text' => $curriculum ? 'Follows this curriculum' : 'Curriculum not stated', 'met' => $curriculum],
                        ['text' => 'Written in '.strtoupper((string) $course->original_locale), 'met' => $language],
                    ],
                ];
            })
            ->sortBy([['score', 'desc'], [fn ($row) => $row['course']->id, 'asc']])->take($limit)->values();
    }

    /** @return Collection<int, LibraryResource> */
    public function materialFor(Room $room, int $limit = 12): Collection
    {
        return LibraryResource::query()
            ->where('status', ResourceStatus::Published->value)
            ->where('subject_id', $room->subject_id)
            ->where(fn ($q) => $q->where('level_id', $room->academic_level_id)->orWhereNull('level_id'))
            ->orderByRaw('case when level_id = ? then 0 else 1 end', [$room->academic_level_id])
            ->orderByRaw('case when curriculum_id = ? then 0 else 1 end', [$room->curriculum_id])
            ->orderByDesc('id')
            ->limit($limit)->get();
    }

    /** The marketplace hand-off for "request tutoring help", prefilled with the room's scope. */
    public function tutoringRequestUrl(Room $room): ?string
    {
        if (! Route::has('marketplace.find')) {
            return null;
        }

        return route('marketplace.find', array_filter([
            'subject_id' => $room->subject_id,
            'level_id' => $room->academic_level_id,
            'curriculum_id' => $room->curriculum_id,
            'languages' => $room->language,
        ]));
    }

    /** @return Collection<int, string> */
    private function viewerLanguages(User $viewer): Collection
    {
        try {
            $prefs = $this->languages->learnerPreferences($viewer);
            $codes = array_merge((array) ($prefs->teaching ?? []), (array) ($prefs->understands ?? []));
        } catch (\Throwable) {
            $codes = [];
        }

        return collect($codes)->map(fn ($c) => is_array($c) ? ($c['code'] ?? null) : $c)->filter()->unique()->values();
    }
}
