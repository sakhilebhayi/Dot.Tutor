<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Marketplace\Contracts\AcademicScopePort;

/**
 * The marketplace's view of the academic structure: ordered levels inside curricula. A teaching scope is valid
 * when its curriculum and levels exist and are active, the levels belong to one curriculum in the right order,
 * and the subject is offered at one or more levels of the range (a tutor may teach a level the seeded catalogue
 * does not list, as long as the subject is taught somewhere in the range).
 */
class AcademicScopeAdapter implements AcademicScopePort
{
    /** @var array<int, ?AcademicLevel> */
    private array $levels = [];

    public function __construct(private readonly AcademicScope $scope) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function levelRank(int $levelId): ?int
    {
        return $this->level($levelId)?->position;
    }

    public function levelCurriculum(int $levelId): ?int
    {
        $curriculum = $this->level($levelId)?->curriculum_id;

        return $curriculum === null ? null : (int) $curriculum;
    }

    public function levelName(int $levelId): ?string
    {
        return $this->level($levelId)?->name;
    }

    public function curriculumName(int $curriculumId): ?string
    {
        return Curriculum::query()->whereKey($curriculumId)->value('name');
    }

    public function validateTeachingScope(int $subjectId, ?int $curriculumId, ?int $levelMinId, ?int $levelMaxId): ?string
    {
        if ($curriculumId === null && $levelMinId === null && $levelMaxId === null) {
            return null;
        }

        $curriculum = null;
        if ($curriculumId !== null) {
            $curriculum = Curriculum::query()->find($curriculumId);
            if ($curriculum === null || ! $curriculum->is_active) {
                return 'That curriculum does not exist or is no longer offered.';
            }
        }

        $min = $levelMinId !== null ? $this->level($levelMinId) : null;
        $max = $levelMaxId !== null ? $this->level($levelMaxId) : null;
        if (($levelMinId !== null && ($min === null || ! $min->is_active)) || ($levelMaxId !== null && ($max === null || ! $max->is_active))) {
            return 'One of those levels does not exist or is no longer offered.';
        }

        $min ??= $max;
        $max ??= $min;

        if ($min === null) {
            // A curriculum without levels: the subject must be taught somewhere in it.
            $offered = AcademicLevel::query()->where('curriculum_id', $curriculum->id)->where('is_active', true)->pluck('id')
                ->contains(fn ($levelId) => $this->scope->isOffered($subjectId, (int) $levelId));

            return $offered ? null : 'That subject is not offered in '.$curriculum->name.'.';
        }

        if ($min->curriculum_id !== $max->curriculum_id) {
            return 'Both ends of a level range must come from the same curriculum.';
        }
        if ($curriculum !== null && $min->curriculum_id !== $curriculum->id) {
            return 'Those levels belong to another curriculum.';
        }
        if ($min->position > $max->position) {
            return 'The lowest level comes after the highest level; swap them.';
        }

        $levelIds = AcademicLevel::query()->where('curriculum_id', $min->curriculum_id)
            ->whereBetween('position', [$min->position, $max->position])->where('is_active', true)->pluck('id');

        foreach ($levelIds as $levelId) {
            if ($this->scope->isOffered($subjectId, (int) $levelId)) {
                return null;
            }
        }

        return 'That subject is not offered at any level from '.$min->name.' to '.$max->name.'.';
    }

    private function level(int $levelId): ?AcademicLevel
    {
        if (! array_key_exists($levelId, $this->levels)) {
            $this->levels[$levelId] = AcademicLevel::query()->find($levelId);
        }

        return $this->levels[$levelId];
    }
}
