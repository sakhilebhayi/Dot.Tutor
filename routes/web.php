<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonResourceController;
use App\Http\Controllers\SessionRatingController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TutorAvailabilityController;
use App\Http\Controllers\TutorBookingController;
use App\Http\Controllers\TutorProfileController;
use App\Http\Controllers\TutorProfileReviewController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::match(['get', 'post'], '/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
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
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/notifications/read', [DashboardController::class, 'markNoticesRead'])->name('notifications.read');

    // Becoming a tutor: one application per user, editable afterwards.
    Route::get('/tutor/profile', [TutorProfileController::class, 'edit'])->name('tutor.profile.edit');
    Route::post('/tutor/profile', [TutorProfileController::class, 'store'])->name('tutor.profile.store');
    Route::put('/tutor/profile', [TutorProfileController::class, 'update'])->name('tutor.profile.update');

    Route::get('/tutor/availability', [TutorAvailabilityController::class, 'index'])->name('tutor.availability.index');
    Route::post('/tutor/availability/windows', [TutorAvailabilityController::class, 'storeWindow'])->name('tutor.availability.windows.store');
    Route::delete('/tutor/availability/windows/{window}', [TutorAvailabilityController::class, 'destroyWindow'])->name('tutor.availability.windows.destroy');
    Route::post('/tutor/availability/blocked-dates', [TutorAvailabilityController::class, 'storeBlockedDate'])->name('tutor.availability.blocked.store');
    Route::delete('/tutor/availability/blocked-dates/{blockedDate}', [TutorAvailabilityController::class, 'destroyBlockedDate'])->name('tutor.availability.blocked.destroy');

    Route::get('/tutors', [TutorBookingController::class, 'browse'])->name('tutors.browse');
    Route::get('/tutors/{tutorProfile}', [TutorBookingController::class, 'show'])->name('tutors.show');
    Route::post('/tutors/{tutorProfile}/sessions', [TutorBookingController::class, 'store'])
        ->middleware('throttle:bookings')
        ->name('tutors.sessions.store');

    Route::get('/sessions/{tutorSession}', [TutorBookingController::class, 'showSession'])->name('sessions.show');
    Route::post('/sessions/{tutorSession}/cancel', [TutorBookingController::class, 'cancel'])->name('sessions.cancel');
    Route::post('/sessions/{tutorSession}/confirm', [TutorBookingController::class, 'confirm'])->name('sessions.confirm');
    Route::post('/sessions/{tutorSession}/complete', [TutorBookingController::class, 'complete'])->name('sessions.complete');
    Route::post('/sessions/{tutorSession}/rating', [SessionRatingController::class, 'store'])->name('sessions.rating.store');

    Route::post('/sessions/{tutorSession}/resources', [LessonResourceController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('resources.store');
    Route::get('/resources/{lessonResource}/download', [LessonResourceController::class, 'download'])->name('resources.download');
    Route::delete('/resources/{lessonResource}', [LessonResourceController::class, 'destroy'])->name('resources.destroy');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/tutor-profiles', [TutorProfileReviewController::class, 'index'])->name('tutor-profiles.index');
        Route::post('/tutor-profiles/{tutorProfile}/approve', [TutorProfileReviewController::class, 'approve'])->name('tutor-profiles.approve');
        Route::post('/tutor-profiles/{tutorProfile}/reject', [TutorProfileReviewController::class, 'reject'])->name('tutor-profiles.reject');
        Route::post('/tutor-profiles/{tutorProfile}/suspend', [TutorProfileReviewController::class, 'suspend'])->name('tutor-profiles.suspend');
        Route::post('/tutor-profiles/{tutorProfile}/reinstate', [TutorProfileReviewController::class, 'reinstate'])->name('tutor-profiles.reinstate');

        Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
        Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
        Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
        Route::post('/subjects/{subject}/activate', [SubjectController::class, 'activate'])->name('subjects.activate');
        Route::post('/subjects/{subject}/deactivate', [SubjectController::class, 'deactivate'])->name('subjects.deactivate');
    });
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
