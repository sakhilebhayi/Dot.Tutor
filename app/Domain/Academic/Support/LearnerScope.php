<?php

namespace App\Domain\Academic\Support;

/** Read-only answer of AcademicScope::forLearner(). Subject ids are catalogue (canonical) ids. */
final readonly class LearnerScope
{
    /** @param  list<int>  $subjectIds */
    public function __construct(
        public int $userId,
        public ?int $countryId,
        public ?int $curriculumId,
        public ?string $curriculumCode,
        public ?int $levelId,
        public ?string $levelCode,
        public ?string $stageCode,
        public array $subjectIds,
        public ?int $institutionId,
        public bool $onboarded,
    ) {}
}
