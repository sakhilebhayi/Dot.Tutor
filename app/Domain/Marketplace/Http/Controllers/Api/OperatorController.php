<?php

namespace App\Domain\Marketplace\Http\Controllers\Api;

use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Enums\ReviewStatus;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Models\QualificationEvidence;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Marketplace\Services\BookingPresenter;
use App\Domain\Marketplace\Services\PrivateFiles;
use App\Domain\Marketplace\Services\ReviewService;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Services\VerificationService;
use App\Http\Controllers\Controller;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator work for the marketplace (behind the existing `operator`
 * middleware until the ops agent's permission matrix lands): the evidence
 * review queue, verification levels, held reviews and booking disputes.
 */
class OperatorController extends Controller
{
    public function qualifications(Request $request, TutorPresenter $presenter): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(QualificationStatus::values())]])['status'] ?? QualificationStatus::Submitted->value;

        $rows = TutorQualification::query()
            ->with(['evidence', 'tutorProfile.user'])
            ->where('status', $status)
            ->orderBy('submitted_at')->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows->map(fn (TutorQualification $q) => [
            ...$presenter->qualification($q, owner: true),
            'tutor' => ['id' => $q->tutor_profile_id, 'name' => $q->tutorProfile->displayName(), 'level' => $q->tutorProfile->verification_level],
            'submitted_at' => $q->submitted_at?->toIso8601String(),
            'evidence' => $q->evidence->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->original_name, 'mime' => $e->mime, 'size_bytes' => $e->size_bytes, 'sha256' => $e->sha256,
                'url' => route('marketplace.api.operator.evidence', $e),
            ])->all(),
        ])->all()]);
    }

    public function evidence(QualificationEvidence $evidence, PrivateFiles $files): StreamedResponse
    {
        abort_unless($files->exists($evidence->path), 404);

        return $files->stream($evidence->path, $evidence->mime, $evidence->original_name);
    }

    public function decideQualification(Request $request, TutorQualification $qualification, string $decision, VerificationService $verification, TutorPresenter $presenter): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000']]);
        $q = match ($decision) {
            'verify' => $verification->verifyQualification($qualification, $request->user(), $data['note']),
            'reject' => $verification->rejectQualification($qualification, $request->user(), $data['note']),
            'revoke' => $verification->revokeQualification($qualification, $request->user(), $data['note']),
            default => abort(404),
        };

        return response()->json(['data' => $presenter->qualification($q->load('evidence'), owner: true)]);
    }

    public function setLevel(Request $request, TutorProfile $tutorProfile, VerificationService $verification, TutorPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'level' => ['present', 'nullable', Rule::in(VerificationLevel::values())],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $profile = $verification->assignLevel($tutorProfile, VerificationLevel::tryFrom((string) $data['level']), $request->user(), $data['reason']);

        return response()->json(['data' => $presenter->verification($profile)]);
    }

    public function verificationHistory(TutorProfile $tutorProfile): JsonResponse
    {
        return response()->json(['data' => $tutorProfile->verificationDecisions()->with('operator')->get()->map(fn ($d) => [
            'id' => $d->id,
            'subject' => $d->subject,
            'qualification_id' => $d->tutor_qualification_id,
            'from' => $d->from_value,
            'to' => $d->to_value,
            'reason' => $d->reason,
            'evidence_ids' => $d->evidence_ids,
            'operator' => $d->operator?->name,
            'at' => $d->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['held', 'published', 'removed'])]])['status'] ?? 'held';

        return response()->json(['data' => SessionRating::query()->where('status', $status)->withCount('reports')->orderBy('id')->limit(100)->get()
            ->map(fn (SessionRating $r) => $r->only(['id', 'session_id', 'rating', 'review', 'status', 'held_reason', 'reports_count']))->all()]);
    }

    public function moderateReview(Request $request, SessionRating $review, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['publish', 'remove'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $r = $reviews->moderate($review, $request->user(), $data['decision'] === 'publish' ? ReviewStatus::Published : ReviewStatus::Removed, $data['reason']);

        return response()->json(['data' => $r->only(['id', 'status', 'held_reason'])]);
    }

    public function resolveDispute(Request $request, TutorSession $tutorSession, BookingFlow $flow, BookingPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['completed', 'cancelled'])],
            'refund_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $session = $flow->resolveDispute($tutorSession, $request->user(), $data['outcome'], (int) $data['refund_percent'], $data['reason']);

        return response()->json(['data' => $presenter->session($session)]);
    }
}
