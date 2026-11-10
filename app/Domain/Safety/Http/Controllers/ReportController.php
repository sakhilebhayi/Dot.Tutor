<?php

namespace App\Domain\Safety\Http\Controllers;

use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\Message;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /** Report anything in config('safety.reports.reportable') by alias and id. */
    public function store(Request $request, ReportSink $sink): JsonResponse|RedirectResponse
    {
        $types = array_filter(config('safety.reports.reportable'), fn (string $class) => class_exists($class));

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys($types))],
            'id' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::enum(ReportCategory::class)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var class-string<Model> $class */
        $class = $types[$validated['type']];
        $reportable = $class::query()->findOrFail($validated['id']);

        // Reporting must not reveal what the reporter cannot see: where the model has a policy, it decides.
        if (Gate::getPolicyFor($reportable) !== null) {
            $ability = method_exists(Gate::getPolicyFor($reportable), 'report') ? 'report' : 'view';
            Gate::authorize($ability, $reportable);
        }

        return $this->file($request, $sink, $reportable, ReportCategory::from($validated['category']), $validated['details'] ?? null);
    }

    public function message(Request $request, Message $message, ReportSink $sink): JsonResponse|RedirectResponse
    {
        Gate::authorize('report', $message);

        $validated = $request->validate([
            'category' => ['required', Rule::enum(ReportCategory::class)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->file($request, $sink, $message, ReportCategory::from($validated['category']), $validated['details'] ?? null);
    }

    private function file(Request $request, ReportSink $sink, Model $reportable, ReportCategory $category, ?string $details): JsonResponse|RedirectResponse
    {
        $report = $sink->report($request->user(), $reportable, $category, $details);

        if ($request->expectsJson()) {
            return response()->json(['report' => [
                'id' => $report->id, 'category' => $report->category->value, 'priority' => $report->priority->value,
                'status' => $report->status, 'incident' => $report->incident?->reference,
            ]], 201);
        }

        return back(303)->with('status', 'Thank you. Your report was sent to the Dot.Tutor safety team.');
    }
}
