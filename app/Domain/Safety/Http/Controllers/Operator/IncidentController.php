<?php

namespace App\Domain\Safety\Http\Controllers\Operator;

use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\IncidentEvent;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\Incidents;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $incidents = Incident::query()->orderByRaw("case when status = 'closed' then 1 else 0 end")
            ->orderByDesc('escalation_level')->orderBy('respond_by')->orderByDesc('id')->limit(200)->get()
            ->map(fn (Incident $i) => $this->present($i))->all();

        return $request->expectsJson() ? response()->json(['incidents' => $incidents]) : view('safety::operator.incidents', ['incidents' => $incidents]);
    }

    public function show(Request $request, Incident $incident): JsonResponse|View
    {
        $data = ['incident' => $this->present($incident) + [
            'outcome' => $incident->outcome,
            'external_reference' => $incident->external_reference,
            'reports' => $incident->reports()->get()->map(fn (Report $r) => ['id' => $r->id, 'category' => $r->category->value, 'status' => $r->status])->all(),
            'events' => $incident->events()->get()->map(fn (IncidentEvent $e) => [
                'type' => $e->type, 'actor_id' => $e->actor_id, 'body' => $e->body, 'at' => $e->created_at->toIso8601String(),
            ])->all(),
        ]];

        return $request->expectsJson() ? response()->json($data) : view('safety::operator.incident', $data);
    }

    public function store(Request $request, Incidents $incidents): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'severity' => ['required', Rule::in(Incidents::SEVERITIES)],
            'learner_id' => ['nullable', 'integer', 'exists:users,id'],
            'report_id' => ['nullable', 'integer', 'exists:safety_reports,id'],
        ]);

        $incident = $incidents->open($validated['title'], $validated['severity'], $request->user(),
            isset($validated['learner_id']) ? User::find($validated['learner_id']) : null,
            isset($validated['report_id']) ? Report::find($validated['report_id']) : null);

        return $request->expectsJson()
            ? response()->json(['incident' => $this->present($incident)], 201)
            : redirect()->route('safety.operator.incidents.show', $incident, 303);
    }

    public function update(Request $request, Incident $incident, Incidents $incidents): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['acknowledge', 'note', 'refer', 'close'])],
            'body' => [Rule::requiredIf(fn () => in_array($request->input('action'), ['note', 'refer', 'close'], true)), 'nullable', 'string', 'max:5000'],
            'external_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $operator = $request->user();

        match ($validated['action']) {
            'acknowledge' => $incidents->acknowledge($incident, $operator),
            'note' => $incidents->note($incident, $operator, $validated['body']),
            'refer' => $incidents->refer($incident, $operator, $validated['body'], $validated['external_reference'] ?? null),
            'close' => $incidents->close($incident, $operator, $validated['body']),
        };

        return $request->expectsJson()
            ? response()->json(['incident' => $this->present($incident->fresh())])
            : back(303)->with('status', 'Incident updated.');
    }

    /** @return array<string, mixed> */
    private function present(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'title' => $incident->title,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'escalation_level' => $incident->escalation_level,
            'respond_by' => $incident->respond_by?->toIso8601String(),
            'overdue' => $incident->status === Incident::OPEN && $incident->respond_by?->isPast(),
            'owner_id' => $incident->owner_id,
            'learner_id' => $incident->learner_id,
            'created_at' => $incident->created_at->toIso8601String(),
        ];
    }
}
