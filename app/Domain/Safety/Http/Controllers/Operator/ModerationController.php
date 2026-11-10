<?php

namespace App\Domain\Safety\Http\Controllers\Operator;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Enums\ReportPriority;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\Moderation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The moderation queue: open reports, most urgent first (priority, then response deadline). */
class ModerationController extends Controller
{
    public function queue(Request $request): JsonResponse|View
    {
        $reports = Report::query()->whereIn('status', [Report::OPEN, Report::ESCALATED])
            ->with(['incident:id,reference,status', 'reporter:id,name', 'reportedUser:id,name'])
            ->limit(500)->get()
            ->sortBy([fn (Report $a, Report $b) => $a->priority->rank() <=> $b->priority->rank(), fn (Report $a, Report $b) => $a->respond_by <=> $b->respond_by])
            ->values();

        $items = $reports->map(fn (Report $r) => $this->present($r))->all();
        $counts = collect(ReportPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $reports->where('priority', $p)->count()])->all();

        return $request->expectsJson()
            ? response()->json(['reports' => $items, 'counts' => $counts])
            : view('safety::operator.queue', ['reports' => $items, 'counts' => $counts]);
    }

    public function show(Request $request, Report $report): JsonResponse|View
    {
        Audit::record($request->user(), 'safety.report.viewed', $report);

        $data = ['report' => $this->present($report) + [
            'details' => $report->details,
            'snapshot' => $report->snapshot,
            'resolution' => $report->resolution,
            // The unredacted text of a redacted message: evidence for safeguarding staff only.
            'original_body' => $report->reportable instanceof Message ? $report->reportable->original_body : null,
        ]];

        return $request->expectsJson() ? response()->json($data) : view('safety::operator.report', $data);
    }

    public function act(Request $request, Report $report, Moderation $moderation): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(Moderation::ACTIONS)],
            'reason' => ['required', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'severity' => ['nullable', Rule::in(['critical', 'high', 'medium'])],
        ]);

        $row = $moderation->act($request->user(), $validated['action'], $report, null, null, $validated['reason'],
            array_filter(['days' => $validated['days'] ?? null, 'severity' => $validated['severity'] ?? null]));

        return $request->expectsJson()
            ? response()->json(['action' => ['id' => $row->id, 'action' => $row->action], 'report' => ['status' => $report->fresh()->status]], 201)
            : back(303)->with('status', 'Action recorded.');
    }

    /** Act on a person directly (warn, suspend, unsuspend) without a report. */
    public function actOnUser(Request $request, User $user, Moderation $moderation): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['warn', 'suspend', 'unsuspend'])],
            'reason' => ['required', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $row = $moderation->act($request->user(), $validated['action'], null, null, $user, $validated['reason'],
            array_filter(['days' => $validated['days'] ?? null]));

        return $request->expectsJson()
            ? response()->json(['action' => ['id' => $row->id, 'action' => $row->action]], 201)
            : back(303)->with('status', 'Action recorded.');
    }

    /** @return array<string, mixed> */
    private function present(Report $report): array
    {
        return [
            'id' => $report->id,
            'type' => $report->reportable_type,
            'target_id' => $report->reportable_id,
            'category' => $report->category->value,
            'category_label' => $report->category->label(),
            'priority' => $report->priority->value,
            'status' => $report->status,
            'involves_minor' => $report->involves_minor,
            'reporter' => $report->reporter?->only(['id', 'name']),
            'reported_user' => $report->reportedUser?->only(['id', 'name']),
            'respond_by' => $report->respond_by->toIso8601String(),
            'overdue' => $report->isOpen() && $report->respond_by->isPast(),
            'incident' => $report->incident?->only(['id', 'reference', 'status']),
            'created_at' => $report->created_at->toIso8601String(),
        ];
    }
}
