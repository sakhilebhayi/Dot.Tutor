<?php

namespace App\Domain\Learning\Services;

/**
 * Turns mastery rows into plain sentences with fixed templates. Deterministic: the same answers always produce the
 * same words. No AI is involved, and the label says so; every number quoted comes from the learner's marked answers.
 */
class InsightWriter
{
    /**
     * @param  list<array<string, mixed>>  $mastery
     * @return list<string>
     */
    public function sentences(array $mastery): array
    {
        if ($mastery === []) {
            return ['There are no marked answers yet. Insights appear after you complete quizzes or practice.'];
        }
        $window = (int) config('learning.mastery.window');
        $minEvidence = (int) config('learning.mastery.min_evidence');

        $improved = array_values(array_filter($mastery, fn ($r) => $r['trend'] === 'improving'));
        $attention = array_values(array_filter($mastery, fn ($r) => $r['level'] === 'needs_attention'));
        $declining = array_values(array_filter($mastery, fn ($r) => $r['trend'] === 'declining' && $r['level'] !== 'needs_attention'));
        $secure = array_values(array_filter($mastery, fn ($r) => $r['level'] === 'secure' && $r['trend'] !== 'improving'));
        $thin = array_values(array_filter($mastery, fn ($r) => $r['level'] === 'not_enough_evidence'));

        $sentences = [];
        $improvedNames = $this->names($improved, useStrand: true);
        $attentionNames = $this->names($attention);
        if ($improvedNames !== [] && $attentionNames !== []) {
            $sentences[] = 'You have improved in '.$this->list($improvedNames).'; '.$this->list($attentionNames).' still '.(count($attentionNames) > 1 ? 'need' : 'needs').' attention.';
        } elseif ($improvedNames !== []) {
            $sentences[] = 'You have improved in '.$this->list($improvedNames).'.';
        } elseif ($attentionNames !== []) {
            $sentences[] = $this->sentenceCase($this->list($attentionNames)).' still '.(count($attentionNames) > 1 ? 'need' : 'needs').' attention.';
        }

        foreach ($improved as $row) {
            $sentences[] = "{$this->sentenceCase($row['topic'])}: {$row['earlier_percent']}% of marks earlier, {$row['later_percent']}% in your most recent answers.";
        }
        foreach ($attention as $row) {
            $sentences[] = "{$this->sentenceCase($row['topic'])}: {$row['recent_accuracy_percent']}% of marks across your last ".min($row['answered'], $window).' answers.';
        }
        foreach ($declining as $row) {
            $sentences[] = "{$this->sentenceCase($row['topic'])} has slipped: {$row['earlier_percent']}% earlier, {$row['later_percent']}% recently.";
        }
        if ($secure !== []) {
            $sentences[] = $this->sentenceCase($this->list($this->names($secure))).' '.(count($secure) > 1 ? 'look' : 'looks').' secure (80% of marks or more recently).';
        }
        if ($thin !== []) {
            $sentences[] = 'Not enough answers yet to say anything about '.$this->list($this->names($thin))." (at least {$minEvidence} needed).";
        }

        return $sentences;
    }

    /** @return list<string> */
    private function names(array $rows, bool $useStrand = false): array
    {
        $names = [];
        foreach ($rows as $row) {
            $names[] = $useStrand && $row['strand'] ? $row['strand'] : $row['topic'];
        }

        return array_values(array_unique($names));
    }

    /** @param  list<string>  $items */
    private function list(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    private function sentenceCase(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
