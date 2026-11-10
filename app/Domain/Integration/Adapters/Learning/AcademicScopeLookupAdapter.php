<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Models\Topic;
use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;

/**
 * Courses, resources and question banks name a curriculum, level, subject and topic from the academic structure.
 * Each id must exist and be active; a level must belong to the curriculum; a topic must belong to the subject (and
 * the level, when one is given). A subject not offered at the chosen level is allowed (creators may teach beyond the
 * seeded catalogue), as the academic seam leaves that to the caller.
 */
class AcademicScopeLookupAdapter implements AcademicScopeLookup
{
    public function __construct(private readonly AcademicScope $scope) {}

    public function validate(?int $curriculumId, ?int $levelId, ?int $subjectId, ?int $topicId = null): array
    {
        $errors = [];
        $curriculum = $curriculumId !== null ? Curriculum::query()->find($curriculumId) : null;
        $level = $levelId !== null ? AcademicLevel::query()->find($levelId) : null;
        $subject = $subjectId !== null ? Subject::query()->find($subjectId) : null;

        if ($curriculumId !== null && ($curriculum === null || ! $curriculum->is_active)) {
            $errors['curriculum_id'] = 'That curriculum does not exist or is no longer offered.';
        }
        if ($levelId !== null && ($level === null || ! $level->is_active)) {
            $errors['level_id'] = 'That level does not exist or is no longer offered.';
        } elseif ($level !== null && $curriculum !== null && $level->curriculum_id !== $curriculum->id) {
            $errors['level_id'] = 'That level belongs to another curriculum.';
        }
        if ($subjectId !== null && ($subject === null || ! $subject->is_active)) {
            $errors['subject_id'] = 'That subject does not exist.';
        }

        if ($topicId !== null) {
            $topic = Topic::query()->find($topicId);
            $offering = $topic ? SubjectOffering::query()->find($topic->curriculum_subject_level_id) : null;

            if ($topic === null || ! $topic->is_active || $offering === null) {
                $errors['topic_id'] = 'That topic does not exist.';
            } elseif ($subjectId !== null && $this->scope->canonicalSubjectId($subjectId) !== $this->scope->canonicalSubjectId((int) $offering->subject_id)) {
                $errors['topic_id'] = 'That topic belongs to another subject.';
            } elseif ($levelId !== null && (int) $offering->academic_level_id !== $levelId) {
                $errors['topic_id'] = 'That topic belongs to another level.';
            }
        }

        return $errors;
    }

    public function describe(?int $curriculumId, ?int $levelId, ?int $subjectId, ?int $topicId = null): array
    {
        return [
            'curriculum' => $curriculumId ? Curriculum::query()->whereKey($curriculumId)->value('name') : null,
            'level' => $levelId ? AcademicLevel::query()->whereKey($levelId)->value('name') : null,
            'subject' => $subjectId ? Subject::query()->whereKey($subjectId)->value('name') : null,
            'topic' => $topicId ? Topic::query()->whereKey($topicId)->value('name') : null,
        ];
    }
}
