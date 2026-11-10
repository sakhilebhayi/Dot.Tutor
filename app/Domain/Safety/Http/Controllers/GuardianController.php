<?php

namespace App\Domain\Safety\Http\Controllers;

use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\AgeResolver;
use App\Domain\Safety\Services\GuardianDashboard;
use App\Domain\Safety\Services\Guardians;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A guardian's own page: each linked child, approvals, consent and settings. Only the guardian of a link acts on it. */
class GuardianController extends Controller
{
    public function dashboard(Request $request, GuardianDashboard $dashboard): JsonResponse|View
    {
        $data = $dashboard->for($request->user());

        return $request->expectsJson() ? response()->json($data) : view('safety::guardian-dashboard', $data);
    }

    public function decide(Request $request, GuardianApproval $approval, GuardianApprovals $approvals): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,decline'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $approval = $approvals->decide($approval, $request->user(), $validated['decision'] === 'approve', $validated['note'] ?? null);

        return $request->expectsJson()
            ? response()->json(['approval' => ['id' => $approval->id, 'status' => $approval->status]])
            : back(303)->with('status', 'Decision saved.');
    }

    public function revoke(Request $request, GuardianLink $link, Guardians $guardians): JsonResponse|RedirectResponse
    {
        $this->ownLink($request, $link);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $guardians->revoke($link, $request->user(), $validated['reason'] ?? null);

        return $request->expectsJson()
            ? response()->json(['link' => ['id' => $link->id, 'status' => $link->fresh()->status]])
            : redirect()->route('safety.guardian.dashboard', status: 303)->with('status', 'Link removed and consent withdrawn.');
    }

    public function recording(Request $request, GuardianLink $link, Guardians $guardians): JsonResponse|RedirectResponse
    {
        $this->ownLink($request, $link);
        $validated = $request->validate(['consent' => ['required', 'boolean']]);

        $guardians->setRecordingConsent($link, (bool) $validated['consent'], $request->user());

        return $request->expectsJson()
            ? response()->json(['recording_consent' => $guardians->hasRecordingConsent($link->learner)])
            : back(303)->with('status', 'Recording choice saved.');
    }

    public function approvalSettings(Request $request, GuardianLink $link, Guardians $guardians, AgeResolver $ages): JsonResponse|RedirectResponse
    {
        $this->ownLink($request, $link);

        if ($ages->band($link->learner) !== AgeBand::Teen) {
            throw ValidationException::withMessages(['settings' => 'Approvals for learners under 13 are always required and cannot be changed.']);
        }

        $kinds = config('safety.approvals.kinds');
        $validated = $request->validate(['settings' => ['required', 'array'], 'settings.*' => ['boolean']]);
        $guardians->setTeenApprovals($link, array_intersect_key($validated['settings'], array_flip($kinds)), $request->user());

        return $request->expectsJson()
            ? response()->json(['approval_settings' => $link->fresh()->approval_settings])
            : back(303)->with('status', 'Approval settings saved.');
    }

    private function ownLink(Request $request, GuardianLink $link): void
    {
        abort_unless($link->guardian_id === $request->user()->id && $link->status === GuardianLink::ACTIVE, 403);
    }
}
