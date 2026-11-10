<?php

namespace App\Domain\Academic\Contracts;

use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Support\LearnerScope;
use App\Domain\Academic\Support\TeachingScopeResult;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Seam (architecture section 3): where a learner is academically, and whether
 * a subject/level pair exists. Used by marketplace (matching, teaching scope),
 * community (rooms) and learning (course level). Owner: academic.
 */
interface AcademicScope
{
    /** The learner's country, curriculum, level and subjects; null when they have no learner profile. */
    public function forLearner(User $user): ?LearnerScope;

    /** Is this subject (or the catalogue subject a legacy row points to) offered, and active, at this level? */
    public function isOffered(int $subjectId, int $levelId): bool;

    /**
     * Check a tutor's declared teaching scope. Each pair is valid, or invalid
     * with a reason: unknown_subject, unknown_level, inactive, not_offered.
     * "not_offered" means no seeded offering exists; the caller decides whether
     * to allow it (a tutor may teach beyond the seeded catalogue).
     *
     * @param  list<array{subject_id: int, level_id: int}>  $pairs
     */
    public function validateTeachingScope(array $pairs): TeachingScopeResult;

    /** The catalogue row a subject id stands for (itself, or its canonical row for a legacy duplicate). */
    public function canonicalSubjectId(int $subjectId): int;

    /**
     * The academic levels a legacy subject row's free-text `level` maps onto
     * (e.g. "high_school" -> CAPS and IEB Grades 8 to 12 and Cambridge
     * secondary). Empty when the string is unmapped.
     *
     * @return list<int>
     */
    public function levelIdsForLegacySubject(int $subjectId): array;

    /**
     * Active offerings at a level, subject loaded, ordered by subject name.
     *
     * @return Collection<int, SubjectOffering>
     */
    public function offeringsAtLevel(int $levelId): Collection;
}
