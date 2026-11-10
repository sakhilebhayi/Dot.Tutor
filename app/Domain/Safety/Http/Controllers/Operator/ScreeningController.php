<?php

namespace App\Domain\Safety\Http\Controllers\Operator;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Enums\ScreeningKind;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Domain\Safety\Services\Screening;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Operator-recorded tutor screening: record with evidence, a second operator reviews, revoke. */
class ScreeningController extends Controller
{
    public function index(Request $request, Screening $screening): JsonResponse
    {
        $checks = ScreeningCheck::query()->with('user:id,name')->orderByRaw("case when status = 'pending_review' then 0 else 1 end")
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (ScreeningCheck $c) => $this->present($c))->all();

        return response()->json(['checks' => $checks, 'required_kinds' => config('safety.screening.required_kinds')]);
    }

    public function forUser(User $user, Screening $screening): JsonResponse
    {
        return response()->json([
            'user_id' => $user->id,
            'cleared_for_minors' => $screening->isClearedForMinors($user),
            'missing_kinds' => $screening->missingKinds($user),
            'checks' => ScreeningCheck::query()->where('user_id', $user->id)->orderByDesc('id')->get()->map(fn ($c) => $this->present($c))->all(),
        ]);
    }

    public function store(Request $request, User $user, Screening $screening): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::enum(ScreeningKind::class)],
            'checked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:checked_on'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:'.config('safety.screening.evidence_max_kb')],
        ]);

        $check = $screening->record($user, ScreeningKind::from($validated['kind']), $validated, $request->file('evidence'), $request->user());

        return $request->expectsJson() ? response()->json(['check' => $this->present($check)], 201) : back(303)->with('status', 'Screening recorded for review.');
    }

    public function review(Request $request, ScreeningCheck $check, Screening $screening): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:cleared,not_cleared'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $check = $screening->review($check, $request->user(), $validated['decision'] === 'cleared', $validated['note'] ?? null);

        return $request->expectsJson() ? response()->json(['check' => $this->present($check)]) : back(303)->with('status', 'Review saved.');
    }

    public function revoke(Request $request, ScreeningCheck $check, Screening $screening): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $screening->revoke($check, $request->user(), $validated['reason']);

        return $request->expectsJson() ? response()->json(['check' => $this->present($check->fresh())]) : back(303);
    }

    public function evidence(Request $request, ScreeningCheck $check, Screening $screening): Response
    {
        abort_if($check->evidence_path === null || ! $screening->evidenceDisk()->exists($check->evidence_path), 404);
        Audit::record($request->user(), 'safety.screening.evidence_viewed', $check);

        return response($screening->evidenceDisk()->get($check->evidence_path), 200, [
            'Content-Type' => $screening->evidenceDisk()->mimeType($check->evidence_path) ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="screening-'.$check->id.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ScreeningCheck $check): array
    {
        return [
            'id' => $check->id,
            'user' => $check->user?->only(['id', 'name']),
            'kind' => $check->kind->value,
            'kind_label' => $check->kind->label(),
            'status' => $check->status,
            'current' => $check->isCurrent(),
            'reference' => $check->reference,
            'checked_on' => $check->checked_on->toDateString(),
            'expires_on' => $check->expires_on?->toDateString(),
            'has_evidence' => $check->evidence_path !== null,
            'recorded_by_id' => $check->recorded_by_id,
            'reviewed_by_id' => $check->reviewed_by_id,
            'reviewed_at' => $check->reviewed_at?->toIso8601String(),
        ];
    }
}
