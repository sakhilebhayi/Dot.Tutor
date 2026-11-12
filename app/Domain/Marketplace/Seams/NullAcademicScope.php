<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\AcademicScopePort;

/** No academic structure on this install yet: scopes are subject-only. */
class NullAcademicScope implements AcademicScopePort
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function levelRank(int $levelId): ?int
    {
        return null;
    }

    public function levelCurriculum(int $levelId): ?int
    {
        return null;
    }

    public function levelName(int $levelId): ?string
    {
        return null;
    }

    public function curriculumName(int $curriculumId): ?string
    {
        return null;
    }

    public function validateTeachingScope(int $subjectId, ?int $curriculumId, ?int $levelMinId, ?int $levelMaxId): ?string
    {
        if ($curriculumId !== null || $levelMinId !== null || $levelMaxId !== null) {
            return 'Curricula and levels are not available yet. Save the subject on its own; you can narrow it to a curriculum and levels once they are.';
        }

        return null;
    }
}
