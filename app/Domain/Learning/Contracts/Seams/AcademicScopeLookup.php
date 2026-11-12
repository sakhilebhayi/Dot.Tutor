<?php

namespace App\Domain\Learning\Contracts\Seams;

/**
 * The learning side of the academic seam (Academic\Contracts\AcademicScope). Validates and names the curriculum,
 * level, subject and topic a course, resource or question bank refers to.
 */
interface AcademicScopeLookup
{
    /**
     * @return array<string, string> validation errors keyed by field (curriculum_id, level_id, subject_id, topic_id)
     */
    public function validate(?int $curriculumId, ?int $levelId, ?int $subjectId, ?int $topicId = null): array;

    /**
     * @return array{curriculum: ?string, level: ?string, subject: ?string, topic: ?string}
     */
    public function describe(?int $curriculumId, ?int $levelId, ?int $subjectId, ?int $topicId = null): array;
}
