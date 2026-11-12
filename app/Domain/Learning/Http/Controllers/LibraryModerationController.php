<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\ResourceReport;
use App\Domain\Learning\Services\LibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Operator side of the library: the review queue, recorded reviews and report decisions. */
class LibraryModerationController extends LearningController
{
    public function __construct(private LibraryService $library) {}

    public function queue(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_platform_operator, 403);

        return response()->json(['data' => [
            'pending_review' => LibraryResource::query()->where('status', ResourceStatus::PendingReview->value)->orderBy('id')->get(['id', 'title', 'type', 'licence', 'source_url', 'owner_id', 'created_at']),
            'open_reports' => ResourceReport::query()->whereIn('status', ['open', 'countered'])->orderBy('id')->get(['id', 'resource_id', 'kind', 'details', 'claimant_name', 'work_reference', 'status', 'counter_notice', 'created_at']),
        ]]);
    }

    public function review(Request $request, LibraryResource $resource): JsonResponse|RedirectResponse
    {
        Gate::authorize('review', $resource);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['approved', 'source_confirmed', 'changes_requested', 'rejected'])],
            'notes' => [in_array($request->input('outcome'), ['approved', 'source_confirmed'], true) ? 'nullable' : 'required', 'string', 'max:3000'],
        ]);
        $review = $this->library->review($request->user(), $resource, $data['outcome'], $data['notes'] ?? null);
        $resource->refresh();

        return $this->wantsJson($request)
            ? response()->json(['data' => ['review_id' => $review->id, 'quality_tier' => $resource->quality_tier->value, 'status' => $resource->status->value, 'reviewer' => $review->reviewer_name]], 201)
            : back()->with('status', 'Review recorded.');
    }

    public function decide(Request $request, ResourceReport $report): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->is_platform_operator, 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['uphold', 'dismiss'])],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $report = $this->library->decide($request->user(), $report, $data['decision'], $data['notes'] ?? null);

        return $this->wantsJson($request)
            ? response()->json(['data' => ['status' => $report->status, 'resource_status' => $report->resource->status->value]])
            : back()->with('status', 'Decision recorded.');
    }
}
