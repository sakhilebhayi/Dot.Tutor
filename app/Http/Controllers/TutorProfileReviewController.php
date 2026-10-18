<?php

namespace App\Http\Controllers;

use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Services\TutorModeration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TutorProfileReviewController extends Controller
{
    public function __construct(private TutorModeration $moderation) {}

    public function index(): View
    {
        $byStatus = fn (TutorProfileStatus $status, string $order = 'created_at') => TutorProfile::query()
            ->with(['user', 'subjects'])
            ->where('status', $status->value)
            ->orderBy($order)
            ->get();

        return view('operator.tutor-profiles.index', [
            'pendingProfiles' => $byStatus(TutorProfileStatus::Pending),
            'approvedProfiles' => $byStatus(TutorProfileStatus::Approved, 'updated_at'),
            'suspendedProfiles' => $byStatus(TutorProfileStatus::Suspended, 'updated_at'),
        ]);
    }

    public function approve(Request $request, TutorProfile $tutorProfile): RedirectResponse
    {
        return $this->respond(
            $this->moderation->approve($tutorProfile, $request->user()),
            'Tutor profile approved.',
        );
    }

    public function reject(Request $request, TutorProfile $tutorProfile): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->respond(
            $this->moderation->reject($tutorProfile, $request->user(), $validated['reason']),
            'Tutor profile rejected.',
        );
    }

    public function suspend(Request $request, TutorProfile $tutorProfile): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->respond(
            $this->moderation->suspend($tutorProfile, $request->user(), $validated['reason']),
            'Tutor suspended. Their pending requests were cancelled.',
        );
    }

    public function reinstate(Request $request, TutorProfile $tutorProfile): RedirectResponse
    {
        return $this->respond(
            $this->moderation->reinstate($tutorProfile, $request->user()),
            'Tutor reinstated.',
        );
    }

    private function respond(bool $done, string $message): RedirectResponse
    {
        return redirect()->route('operator.tutor-profiles.index')->with(
            'status',
            $done ? $message : 'That profile is no longer in a state this action applies to.',
        );
    }
}
