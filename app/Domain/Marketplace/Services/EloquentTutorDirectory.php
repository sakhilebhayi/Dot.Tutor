<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Contracts\TutorDirectory;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;

/** Marketplace's own TutorDirectory: approved tutors only, with scope, languages and verification. */
class EloquentTutorDirectory implements TutorDirectory
{
    public function __construct(private TutorPresenter $presenter, private LanguageProfilePort $languages, private AcademicScopePort $academic) {}

    public function approved(array $filters = []): array
    {
        return TutorProfile::query()
            ->with(['user', 'teachingScopes', 'subjects'])
            ->where('status', TutorProfileStatus::Approved->value)
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->whereHas('subjects', fn ($s) => $s->where('subjects.id', $id)))
            ->orderBy('id')
            ->limit(min(200, (int) ($filters['limit'] ?? 50)))
            ->get()
            ->filter(fn (TutorProfile $p) => empty($filters['language']) || collect($this->languagesOf($p))->contains('code', $filters['language']))
            ->filter(fn (TutorProfile $p) => empty($filters['level_id']) || $p->teachingScopes->isEmpty()
                || $p->teachingScopes->contains(fn (TutorTeachingScope $s) => $this->scopeCovers($s, (int) $filters['level_id'])))
            ->map(fn (TutorProfile $p) => $this->card($p))
            ->values()->all();
    }

    /**
     * A scope covers a level when the level sits inside its range (inclusive) in the same curriculum, as matching
     * reads it. Comparing the level id with the two range ends only (the earlier rule) left out every level inside a
     * range: a Grade 10 to 12 tutor was not listed for Grade 11 rooms (QA, browser run). Without the academic data
     * the ends are all that can be checked.
     */
    private function scopeCovers(TutorTeachingScope $scope, int $levelId): bool
    {
        if ($scope->level_min_id === null && $scope->level_max_id === null) {
            return true;
        }
        $minId = (int) ($scope->level_min_id ?? $scope->level_max_id);
        $maxId = (int) ($scope->level_max_id ?? $scope->level_min_id);
        if (in_array($levelId, [$minId, $maxId], true)) {
            return true;
        }
        if (! $this->academic->isAvailable()) {
            return false;
        }
        $curriculum = $this->academic->levelCurriculum($levelId);
        if ($curriculum === null || $this->academic->levelCurriculum($minId) !== $curriculum) {
            return false;
        }
        [$rank, $min, $max] = [$this->academic->levelRank($levelId), $this->academic->levelRank($minId), $this->academic->levelRank($maxId)];

        return $rank !== null && $min !== null && $max !== null && $rank >= $min && $rank <= $max;
    }

    public function find(int $tutorProfileId): ?array
    {
        $profile = TutorProfile::query()->with(['user', 'teachingScopes', 'subjects'])
            ->where('status', TutorProfileStatus::Approved->value)->find($tutorProfileId);

        return $profile ? $this->card($profile) : null;
    }

    /** @return array<string, mixed> */
    private function card(TutorProfile $p): array
    {
        return [
            'id' => $p->id,
            'user_id' => $p->user_id,
            'name' => $p->displayName(),
            'headline' => $p->headline,
            'subjects' => $p->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'scopes' => $p->teachingScopes->map(fn ($s) => $s->only(['subject_id', 'curriculum_id', 'level_min_id', 'level_max_id']))->values()->all(),
            'languages' => $this->languagesOf($p),
            'verification' => $this->presenter->verification($p),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function languagesOf(TutorProfile $p): array
    {
        return $p->user && $this->languages->isAvailable() ? $this->languages->teachingLanguages($p->user) : [];
    }
}
