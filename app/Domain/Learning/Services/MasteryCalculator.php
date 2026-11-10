<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Models\AttemptAnswer;
use App\Models\User;

/**
 * Topic mastery from real marked answers, recomputed on every read so it can never drift from the evidence.
 *
 * - recent accuracy: marks earned / marks possible over the last N answers in the topic (config learning.mastery.window)
 * - level: not_enough_evidence below min_evidence answers; secure, developing or needs_attention by recent accuracy
 * - trend: the last 2N answers split into an earlier and a later half (each at least min_evidence); a change of at
 *   least trend_points percentage points is improving or declining, otherwise steady; unknown with too little data
 */
class MasteryCalculator
{
    /**
     * @return list<array{subject_id: ?int, strand: ?string, topic: string, answered: int, recent_accuracy_percent: ?int, level: string, trend: string, earlier_percent: ?int, later_percent: ?int, last_answered_at: ?string}>
     */
    public function forUser(User $user): array
    {
        $config = config('learning.mastery');
        $window = (int) $config['window'];
        $minEvidence = (int) $config['min_evidence'];

        $answers = AttemptAnswer::query()
            ->where('user_id', $user->id)
            ->where('counts_for_mastery', true)
            ->orderBy('answered_at')->orderBy('id')
            ->get(['subject_id', 'strand', 'topic', 'marks_awarded', 'marks_possible', 'answered_at']);

        $rows = [];
        foreach ($answers->groupBy(fn ($a) => ($a->subject_id ?? 0).'|'.mb_strtolower($a->topic)) as $group) {
            $count = $group->count();
            $recent = $group->slice(-$window);
            $accuracy = $this->percent($recent);

            $trend = 'unknown';
            $earlierPercent = $laterPercent = null;
            $span = $group->slice(-2 * $window)->values();
            $half = intdiv($span->count(), 2);
            if ($half >= $minEvidence) {
                $earlierPercent = $this->percent($span->slice(0, $span->count() - $half));
                $laterPercent = $this->percent($span->slice(-$half));
                $delta = $laterPercent - $earlierPercent;
                $trend = $delta >= $config['trend_points'] ? 'improving' : ($delta <= -$config['trend_points'] ? 'declining' : 'steady');
            }

            $level = match (true) {
                $count < $minEvidence => 'not_enough_evidence',
                $accuracy >= $config['secure_percent'] => 'secure',
                $accuracy >= $config['developing_percent'] => 'developing',
                default => 'needs_attention',
            };

            $last = $group->last();
            $rows[] = [
                'subject_id' => $last->subject_id,
                'strand' => $last->strand,
                'topic' => $last->topic,
                'answered' => $count,
                'recent_accuracy_percent' => $count < $minEvidence ? null : $accuracy,
                'level' => $level,
                'trend' => $trend,
                'earlier_percent' => $earlierPercent,
                'later_percent' => $laterPercent,
                'last_answered_at' => $last->answered_at?->toIso8601String(),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['subject_id'], mb_strtolower($a['topic'])] <=> [$b['subject_id'], mb_strtolower($b['topic'])]);

        return $rows;
    }

    private function percent($answers): int
    {
        $possible = $answers->sum('marks_possible');

        return $possible === 0 ? 0 : intdiv($answers->sum('marks_awarded') * 100, $possible);
    }
}
