<?php

namespace App\Domain\Ai\Http\Controllers;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Safety\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Safeguarding review of the AI log for operators: flagged, reported or refused interactions first. Listing shows
 * metadata only; opening one (AssistantController::show) is audited. Costs and usage are summed from the log.
 */
class OperatorLogController extends AiController
{
    public function index(Request $request): JsonResponse|View
    {
        abort_unless($request->user()->can('ai.review-log'), 403);
        $filter = in_array($request->query('filter'), ['flagged', 'reported', 'refused', 'all'], true) ? $request->query('filter') : 'flagged';

        $query = AiInteraction::query()->latest('id');
        match ($filter) {
            'flagged' => $query->where(fn ($q) => $q->where('flagged', true)->orWhereNotNull('reported_at')),
            'reported' => $query->whereNotNull('reported_at'),
            'refused' => $query->whereIn('status', ['refused', 'refused_by_model']),
            default => null,
        };
        $rows = $query->paginate(50, ['id', 'user_id', 'actor_role', 'task', 'age_band', 'age_known', 'status', 'refusal_code', 'model', 'flagged', 'flag_reason', 'reported_at', 'cost_micro_usd', 'created_at'])->withQueryString();

        $month = AiInteraction::query()->where('reached_model', true)->where('created_at', '>=', now()->startOfMonth());
        $summary = [
            'requests_this_month' => (clone $month)->count(),
            'cost_this_month_micro_usd' => (int) (clone $month)->sum('cost_micro_usd'),
            'budget_micro_usd' => (int) config('ai.limits.monthly_budget_micro_usd'),
            'flagged_open' => AiInteraction::query()->where('flagged', true)->count(),
            'refused_this_month' => AiInteraction::query()->where('status', 'refused')->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
        Audit::record($request->user(), 'ai.log.listed', null, ['filter' => $filter]);

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $rows->items(), 'summary' => $summary, 'meta' => ['total' => $rows->total()]]);
        }

        return view('ai::operator', ['rows' => $rows, 'summary' => $summary, 'filter' => $filter]);
    }
}
