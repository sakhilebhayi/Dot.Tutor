<?php

namespace App\Domain\Safety\Http\Controllers\Operator;

use App\Domain\Safety\Enums\IntegrityKind;
use App\Domain\Safety\Models\IntegrityCase;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\IntegrityCases;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['cases' => IntegrityCase::query()->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('id')->limit(200)->get()->map(fn (IntegrityCase $c) => $this->present($c))->all()]);
    }

    public function store(Request $request, IntegrityCases $cases): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(IntegrityKind::class)],
            'summary' => ['required', 'string', 'max:5000'],
            'subject_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'report_id' => ['nullable', 'integer', 'exists:safety_reports,id'],
        ]);

        $case = $cases->open(IntegrityKind::from($validated['kind']), $validated['summary'], $request->user(), null,
            isset($validated['subject_user_id']) ? User::find($validated['subject_user_id']) : null,
            isset($validated['report_id']) ? Report::find($validated['report_id']) : null);

        return response()->json(['case' => $this->present($case)], 201);
    }

    public function decide(Request $request, IntegrityCase $case, IntegrityCases $cases): JsonResponse
    {
        $validated = $request->validate([
            'upheld' => ['required', 'boolean'],
            'finding' => ['required', 'string', 'max:5000'],
            'sanction' => ['required', Rule::in(IntegrityCase::SANCTIONS)],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $case = $cases->decide($case, $request->user(), (bool) $validated['upheld'], $validated['finding'], $validated['sanction'], $validated['days'] ?? null);

        return response()->json(['case' => $this->present($case)]);
    }

    /** @return array<string, mixed> */
    private function present(IntegrityCase $case): array
    {
        return [
            'id' => $case->id, 'kind' => $case->kind->value, 'kind_label' => $case->kind->label(), 'status' => $case->status,
            'summary' => $case->summary, 'finding' => $case->finding, 'sanction' => $case->sanction,
            'subject_user_id' => $case->subject_user_id, 'report_id' => $case->report_id,
            'decided_at' => $case->decided_at?->toIso8601String(),
        ];
    }
}
