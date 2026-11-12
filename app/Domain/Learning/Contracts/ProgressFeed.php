<?php

namespace App\Domain\Learning\Contracts;

use App\Models\User;

/**
 * Learning progress for other domains (AI assistant, student UI, guardian UI). Everything returned is computed from
 * recorded learner activity: lesson completions, submitted attempts and issued certificates. Nothing is estimated.
 */
interface ProgressFeed
{
    /**
     * Most recent progress events, newest first.
     *
     * @return list<array{type: string, at: string, title: string, course_id: ?int, assessment_id: ?int, detail: array<string, mixed>}>
     */
    public function recentEvents(User $user, int $limit = 20): array;

    /**
     * Topic mastery computed from the learner's marked answers.
     *
     * @return list<array{subject_id: ?int, strand: ?string, topic: string, answered: int, recent_accuracy_percent: ?int, level: string, trend: string}>
     */
    public function mastery(User $user): array;

    /**
     * Plain-language insights generated deterministically from the mastery data, with their provenance.
     *
     * @return array{label: string, based_on: array{answers: int, attempts: int, last_answer_at: ?string}, sentences: list<string>}
     */
    public function insights(User $user): array;
}
