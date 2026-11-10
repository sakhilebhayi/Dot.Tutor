<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Models\Flashcard;
use App\Domain\Learning\Models\FlashcardReview;
use App\Domain\Learning\Models\FlashcardSet;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Spaced review using the published SM-2 rules: grades 0-5; below 3 resets the card to tomorrow; otherwise the
 * interval grows 1 day, 6 days, then by the card's ease factor, and the ease moves with the grade (floor 1.3).
 */
class SpacedRepetition
{
    public function review(User $user, Flashcard $card, int $grade): FlashcardReview
    {
        $grade = max(0, min(5, $grade));
        $state = FlashcardReview::query()->firstOrNew(
            ['flashcard_id' => $card->id, 'user_id' => $user->id],
            ['repetitions' => 0, 'interval_days' => 0, 'ease' => 2.5, 'due_at' => now()],
        );
        $ease = (float) ($state->ease ?: 2.5);

        if ($grade < 3) {
            $repetitions = 0;
            $interval = 1;
        } else {
            $repetitions = (int) $state->repetitions + 1;
            $interval = match ($repetitions) {
                1 => 1,
                2 => 6,
                default => (int) round(max(1, (int) $state->interval_days) * $ease),
            };
        }
        $ease = max(1.3, $ease + (0.1 - (5 - $grade) * (0.08 + (5 - $grade) * 0.02)));

        $state->fill([
            'repetitions' => $repetitions,
            'interval_days' => $interval,
            'ease' => round($ease, 2),
            'due_at' => now()->addDays($interval),
            'last_reviewed_at' => now(),
            'last_grade' => $grade,
        ])->save();

        return $state;
    }

    /**
     * Cards due now for this learner: never-reviewed cards first (in set order), then overdue cards, oldest due first.
     *
     * @return Collection<int, Flashcard>
     */
    public function due(User $user, FlashcardSet $set, int $limit = 20): Collection
    {
        $states = FlashcardReview::query()->where('user_id', $user->id)
            ->whereIn('flashcard_id', $set->cards()->pluck('id'))->get()->keyBy('flashcard_id');

        $new = $set->cards->filter(fn (Flashcard $card) => ! isset($states[$card->id]));
        $overdue = $set->cards->filter(fn (Flashcard $card) => isset($states[$card->id]) && $states[$card->id]->due_at->lte(now()))
            ->sortBy(fn (Flashcard $card) => $states[$card->id]->due_at->getTimestamp());

        return $new->concat($overdue)->take($limit)->values();
    }
}
