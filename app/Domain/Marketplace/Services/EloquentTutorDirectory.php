<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Contracts\TutorDirectory;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;

/** Marketplace's own TutorDirectory: approved tutors only, with scope, languages and verification. */
class EloquentTutorDirectory implements TutorDirectory
{
    public function __construct(private TutorPresenter $presenter, private LanguageProfilePort $languages) {}

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
                || $p->teachingScopes->contains(fn ($s) => $s->level_min_id === null || in_array((int) $filters['level_id'], [$s->level_min_id, $s->level_max_id], true)))
            ->map(fn (TutorProfile $p) => $this->card($p))
            ->values()->all();
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
