<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\LessonResourceController;
use App\Http\Controllers\SessionRatingController;
use App\Http\Controllers\TutorBookingController;
use App\Http\Controllers\TutorProfileReviewController;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->middleware('throttle:10,1')
    ->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $totalSessions = TutorSession::count();
        $upcomingSessions = TutorSession::whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', now())->count();
        $completedSessions = TutorSession::where('status', 'completed')->count();
        $totalTutors = TutorProfile::where('status', 'approved')->count();
        $availableTutors = TutorProfile::where('status', 'approved')->count();
        $totalRevenue = TutorSession::where('status', 'completed')
            ->selectRaw('COALESCE(SUM(rate * duration_minutes / 60.0), 0) as rev')
            ->value('rev') ?? 0;
        $subjects = Subject::withCount('tutors')->orderBy('name')->get();

        // Scope the session lists to the signed-in user's own bookings (as student)
        // or their own tutor profile's sessions (as tutor). The KPI counts above stay
        // platform-wide aggregates (no PII), but per-session rows — tutor/student
        // names, subjects, times, amounts — must not be visible to every logged-in
        // user regardless of role; there is no admin/staff role in this schema yet
        // to justify a system-wide view here.
        $userId = auth()->id();
        $scopeToOwnSessions = function ($query) use ($userId) {
            $query->where('student_id', $userId)
                ->orWhereHas('tutorProfile', fn ($q) => $q->where('user_id', $userId));
        };

        $upcomingSessionsList = TutorSession::with(['tutorProfile.user', 'subject'])
            ->where($scopeToOwnSessions)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get();
        $recentSessions = TutorSession::with(['tutorProfile.user', 'subject'])
            ->where($scopeToOwnSessions)
            ->latest('starts_at')->limit(8)->get();

        return view('dashboard', compact(
            'totalSessions', 'upcomingSessions', 'completedSessions',
            'totalTutors', 'availableTutors', 'totalRevenue',
            'subjects', 'upcomingSessionsList', 'recentSessions'
        ));
    })->name('dashboard');

    Route::get('/tutors', [TutorBookingController::class, 'browse'])->name('tutors.browse');
    Route::get('/tutors/{tutorProfile}', [TutorBookingController::class, 'show'])->name('tutors.show');
    Route::post('/tutors/{tutorProfile}/sessions', [TutorBookingController::class, 'store'])->name('tutors.sessions.store');

    Route::get('/sessions/{tutorSession}', [TutorBookingController::class, 'showSession'])->name('sessions.show');
    Route::post('/sessions/{tutorSession}/cancel', [TutorBookingController::class, 'cancel'])->name('sessions.cancel');
    Route::post('/sessions/{tutorSession}/confirm', [TutorBookingController::class, 'confirm'])->name('sessions.confirm');
    Route::post('/sessions/{tutorSession}/complete', [TutorBookingController::class, 'complete'])->name('sessions.complete');
    Route::post('/sessions/{tutorSession}/rating', [SessionRatingController::class, 'store'])->name('sessions.rating.store');

    Route::post('/sessions/{tutorSession}/resources', [LessonResourceController::class, 'store'])->name('resources.store');
    Route::get('/resources/{lessonResource}/download', [LessonResourceController::class, 'download'])->name('resources.download');
    Route::delete('/resources/{lessonResource}', [LessonResourceController::class, 'destroy'])->name('resources.destroy');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/tutor-profiles', [TutorProfileReviewController::class, 'index'])->name('tutor-profiles.index');
        Route::post('/tutor-profiles/{tutorProfile}/approve', [TutorProfileReviewController::class, 'approve'])->name('tutor-profiles.approve');
        Route::post('/tutor-profiles/{tutorProfile}/reject', [TutorProfileReviewController::class, 'reject'])->name('tutor-profiles.reject');
    });
});
