<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Models\LegacyLevelMapping;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Support\LearnerScope;
use App\Domain\Academic\Support\TeachingScopeResult;
use App\Models\User;
use Illuminate\Support\Collection;

class DatabaseAcademicScope implements AcademicScope
{
    public function forLearner(User $user): ?LearnerScope
    {
        $profile = LearnerProfile::query()->with(['curriculum', 'level.stage', 'subjects'])->where('user_id', $user->id)->first();

        if (! $profile) {
            return null;
        }

        return new LearnerScope(
            userId: $user->id,
            countryId: $profile->country_id,
            curriculumId: $profile->curriculum_id,
            curriculumCode: $profile->curriculum?->code,
            levelId: $profile->academic_level_id,
            levelCode: $profile->level?->code,
            stageCode: $profile->level?->stage?->code,
            subjectIds: $profile->subjects->map(fn (Subject $s) => $s->canonicalId())->unique()->values()->all(),
            institutionId: $profile->institution_id,
            onboarded: $profile->isOnboarded(),
        );
    }

    public function isOffered(int $subjectId, int $levelId): bool
    {
        return $this->offering($subjectId, $levelId)?->is_active === true;
    }

    public function validateTeachingScope(array $pairs): TeachingScopeResult
    {
        $valid = [];
        $invalid = [];

        foreach ($pairs as $pair) {
            $subjectId = (int) $pair['subject_id'];
            $levelId = (int) $pair['level_id'];
            $subject = Subject::query()->find($subjectId);
            $level = AcademicLevel::query()->find($levelId);

            $reason = match (true) {
                ! $subject => 'unknown_subject',
                ! $level => 'unknown_level',
                ! $subject->is_active || ! $level->is_active => 'inactive',
                default => null,
            };

            $offering = $reason === null ? $this->offering($subjectId, $levelId) : null;
            if ($reason === null && ! $offering) {
                $reason = 'not_offered';
            } elseif ($reason === null && ! $offering->is_active) {
                $reason = 'inactive';
            }

            if ($reason) {
                $invalid[] = ['subject_id' => $subjectId, 'level_id' => $levelId, 'reason' => $reason];
            } else {
                $valid[] = ['subject_id' => $offering->subject_id, 'level_id' => $levelId, 'offering_id' => $offering->id];
            }
        }

        return new TeachingScopeResult($valid, $invalid);
    }

    public function canonicalSubjectId(int $subjectId): int
    {
        return (int) (Subject::query()->whereKey($subjectId)->value('canonical_subject_id') ?? $subjectId);
    }

    public function levelIdsForLegacySubject(int $subjectId): array
    {
        $legacyLevel = Subject::query()->whereKey($subjectId)->value('level');

        if ($legacyLevel === null) {
            return [];
        }

        return LegacyLevelMapping::query()->where('legacy_level', $legacyLevel)->orderBy('academic_level_id')
            ->pluck('academic_level_id')->map(fn ($id) => (int) $id)->all();
    }

    public function offeringsAtLevel(int $levelId): Collection
    {
        return SubjectOffering::query()
            ->active()
            ->where('academic_level_id', $levelId)
            ->whereHas('subject', fn ($q) => $q->where('is_active', true))
            ->with('subject')
            ->get()
            ->sortBy(fn (SubjectOffering $o) => [$o->subject->category === 'languages' ? 1 : 0, $o->subject->name])
            ->values();
    }

    private function offering(int $subjectId, int $levelId): ?SubjectOffering
    {
        return SubjectOffering::query()
            ->where('academic_level_id', $levelId)
            ->whereIn('subject_id', array_unique([$subjectId, $this->canonicalSubjectId($subjectId)]))
            ->orderByRaw('case when subject_id = ? then 0 else 1 end', [$subjectId])
            ->first();
    }
}
