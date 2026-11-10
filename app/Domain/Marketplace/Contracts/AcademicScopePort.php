<?php

namespace App\Domain\Marketplace\Contracts;

/**
 * The marketplace's view of the academic structure (curricula, ordered levels).
 * Owned by the academic agent as Academic\Contracts\AcademicScope; the
 * integration step binds an adapter onto it (decision D-302). The null default
 * reports the structure as unavailable, and the marketplace then works on
 * subjects alone and says so.
 */
interface AcademicScopePort
{
    public function isAvailable(): bool;

    /** Position of a level within its curriculum (higher is more advanced), or null when unknown. */
    public function levelRank(int $levelId): ?int;

    /** The curriculum a level belongs to, or null when unknown. */
    public function levelCurriculum(int $levelId): ?int;

    public function levelName(int $levelId): ?string;

    public function curriculumName(int $curriculumId): ?string;

    /**
     * Null when the scope is valid; otherwise the reason it is not (a level
     * from another curriculum, a range the wrong way round, a subject not
     * offered at that level).
     */
    public function validateTeachingScope(int $subjectId, ?int $curriculumId, ?int $levelMinId, ?int $levelMaxId): ?string;
}
