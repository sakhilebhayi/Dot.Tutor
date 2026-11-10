<?php

namespace App\Domain\Ai\Guardrails;

use App\Domain\Ai\Models\AiInteraction;
use App\Models\User;

/**
 * Per-person and platform cost and request limits (config ai.limits), counted from the interaction log, so the
 * numbers can never drift from what was actually sent. Only requests that reached the model count.
 */
class UsageLimits
{
    public function check(User $user, string $role, string $band): ?Refusal
    {
        $limits = $role === 'tutor' ? config('ai.limits.tutor') : config('ai.limits.learner.'.$band, config('ai.limits.learner.child'));

        $today = AiInteraction::query()->where('user_id', $user->id)->where('reached_model', true)
            ->where('created_at', '>=', now()->startOfDay());
        if ((clone $today)->count() >= (int) $limits['daily_requests']) {
            return new Refusal('daily_limit');
        }
        if ((int) (clone $today)->sum('cost_micro_usd') >= (int) $limits['daily_cost_micro_usd']) {
            return new Refusal('daily_cost_limit');
        }

        $month = (int) AiInteraction::query()->where('reached_model', true)
            ->where('created_at', '>=', now()->startOfMonth())->sum('cost_micro_usd');
        if ($month >= (int) config('ai.limits.monthly_budget_micro_usd')) {
            return new Refusal('monthly_budget');
        }

        return null;
    }

    /** @return array{requests_today: int, requests_limit: int, cost_today_micro_usd: int, cost_limit_micro_usd: int} */
    public function summary(User $user, string $role, string $band): array
    {
        $limits = $role === 'tutor' ? config('ai.limits.tutor') : config('ai.limits.learner.'.$band, config('ai.limits.learner.child'));
        $today = AiInteraction::query()->where('user_id', $user->id)->where('reached_model', true)->where('created_at', '>=', now()->startOfDay());

        return [
            'requests_today' => (clone $today)->count(),
            'requests_limit' => (int) $limits['daily_requests'],
            'cost_today_micro_usd' => (int) (clone $today)->sum('cost_micro_usd'),
            'cost_limit_micro_usd' => (int) $limits['daily_cost_micro_usd'],
        ];
    }

    /** Cost of one call in micro US dollars, rounded up. Unknown models are charged at the most expensive row. */
    public static function costMicroUsd(?string $model, int $inputTokens, int $outputTokens): int
    {
        $prices = config('ai.prices_cents_per_mtok', []);
        $price = $prices[$model] ?? collect($prices)->sortByDesc('output')->first() ?? ['input' => 1000, 'output' => 5000];

        // cents per million tokens -> micro dollars per token = cents / 100
        return (int) ceil(($inputTokens * $price['input'] + $outputTokens * $price['output']) / 100);
    }
}
