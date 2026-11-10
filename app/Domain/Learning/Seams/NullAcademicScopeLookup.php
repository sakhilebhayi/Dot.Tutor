<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Models\Subject;

/**
 * Default until the academic domain merges: subjects are checked against the existing subject catalogue; curricula,
 * levels and topics do not exist yet, so any id for them is refused rather than stored unchecked.
 */
class NullAcademicScopeLookup implements AcademicScopeLookup
{
    public function validate(?int $curriculumId, ?int $levelId, ?int $subjectId, ?int $topicId = null): array
    {
        $errors = [];
        foreach (['curriculum_id' => $curriculumId, 'level_id' => $levelId, 'topic_id' => $topicId] as $field => $id) {
            if ($id !== null) {
                $errors[$field] = 'The academic structure (curricula, levels, topics) is not available yet.';
            }
        }
        if ($subjectId !== null && ! Subject::query()->whereKey($subjectId)->exists()) {
            $errors['subject_id'] = 'That subject does not exist.';
        }

        return $errors;
    }

    public function describe(?int $curriculumId, ?int $levelId, ?int $subjectId, ?int $topicId = null): array
    {
        return [
            'curriculum' => null,
            'level' => null,
            'subject' => $subjectId ? Subject::query()->whereKey($subjectId)->value('name') : null,
            'topic' => null,
        ];
    }
}
