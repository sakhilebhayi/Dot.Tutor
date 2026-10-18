<?php

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\Subject;
use App\Models\TutorSession;
use App\Services\DashboardMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): View
    {
        $user = $request->user();

        // The session lists are always the viewer's own, whatever their role.
        $own = function ($query) use ($user) {
            $query->where('student_id', $user->id)
                ->orWhereHas('tutorProfile', fn ($q) => $q->where('user_id', $user->id));
        };

        $upcomingSessionsList = TutorSession::with(['tutorProfile.user', 'subject'])
            ->where($own)
            ->whereIn('status', SessionStatus::holdingTime())
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        $recentSessions = TutorSession::with(['tutorProfile.user', 'subject'])
            ->where($own)
            ->latest('starts_at')->limit(8)->get();

        $subjects = Subject::active()
            ->withCount(['tutors' => fn ($q) => $q->where('status', TutorProfileStatus::Approved->value)])
            ->orderBy('name')->get();

        return view('dashboard', [
            'metrics' => $metrics->forUser($user),
            'subjects' => $subjects,
            'upcomingSessionsList' => $upcomingSessionsList,
            'recentSessions' => $recentSessions,
            'notices' => $user->unreadNotifications()->latest()->limit(10)->get(),
            'tutorProfile' => $user->tutorProfile,
        ]);
    }

    public function markNoticesRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
