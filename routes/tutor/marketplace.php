<?php

/*
|--------------------------------------------------------------------------
| R-47 marketplace routes (marketplace agent)
|--------------------------------------------------------------------------
|
| Required from routes/web.php, so these run in the web group (session,
| CSRF). The JSON API lives under /api/tutor and answers {error, message} on
| failure (TutorMarketplaceServiceProvider). Rate limiters are defined in the
| provider, never here (route:cache).
|
*/

use App\Domain\Marketplace\Http\Controllers\Api\BookingController;
use App\Domain\Marketplace\Http\Controllers\Api\DiscoveryController;
use App\Domain\Marketplace\Http\Controllers\Api\OperatorController;
use App\Domain\Marketplace\Http\Controllers\Api\ReviewController;
use App\Domain\Marketplace\Http\Controllers\Api\TutorWorkspaceController;
use App\Domain\Marketplace\Http\Controllers\Web\MarketplacePageController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- Public discovery (R-47 polish D-2003)
// A signed-out visitor can search approved tutors and read a storefront: approved tutors only (unapproved is 404),
// tutor free text through safety's view_contact rule (contact details hidden from guests), no learner data.
// Booking, paying, messaging and reporting stay behind sign-in; guests are rate-limited per address
// (tutor-guest-search, tutor-public-pages: TutorStudentServiceProvider).
Route::get('/tutors/{tutorProfile}/photo', [DiscoveryController::class, 'photo'])->middleware('throttle:tutor-public-pages')->name('marketplace.tutors.photo');
Route::get('/find-a-tutor', [MarketplacePageController::class, 'find'])->middleware(['throttle:tutor-guest-search', 'throttle:marketplace-matching'])->name('marketplace.find');
Route::get('/t/{tutorProfile}', [MarketplacePageController::class, 'storefront'])->middleware('throttle:tutor-public-pages')->name('marketplace.tutors.show');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    // ---------------------------------------------------------------- JSON API
    Route::prefix('api/tutor')->name('marketplace.api.')->group(function () {
        // Discovery and matching
        Route::get('/tutors', [DiscoveryController::class, 'index'])->name('tutors.index');
        Route::post('/match', [DiscoveryController::class, 'match'])->middleware('throttle:marketplace-matching')->name('match');
        Route::get('/tutors/{tutorProfile}', [DiscoveryController::class, 'show'])->name('tutors.show');
        Route::get('/tutors/{tutorProfile}/availability', [DiscoveryController::class, 'availability'])->name('tutors.availability');
        Route::get('/tutors/{tutorProfile}/offerings', [DiscoveryController::class, 'offerings'])->name('tutors.offerings');
        Route::get('/tutors/{tutorProfile}/group-sessions', [DiscoveryController::class, 'groupSessions'])->name('tutors.group-sessions');
        Route::get('/tutors/{tutorProfile}/reviews', [DiscoveryController::class, 'reviews'])->name('tutors.reviews');

        // The tutor's own workspace
        Route::prefix('me')->name('me.')->group(function () {
            Route::get('/profile', [TutorWorkspaceController::class, 'show'])->name('profile');
            Route::patch('/profile', [TutorWorkspaceController::class, 'update'])->name('profile.update');
            Route::post('/profile/photo', [TutorWorkspaceController::class, 'uploadPhoto'])->middleware('throttle:uploads')->name('photo.store');
            Route::delete('/profile/photo', [TutorWorkspaceController::class, 'deletePhoto'])->name('photo.destroy');
            Route::put('/scopes', [TutorWorkspaceController::class, 'scopes'])->name('scopes');

            Route::get('/qualifications', [TutorWorkspaceController::class, 'qualifications'])->name('qualifications');
            Route::post('/qualifications', [TutorWorkspaceController::class, 'storeQualification'])->name('qualifications.store');
            Route::patch('/qualifications/{qualification}', [TutorWorkspaceController::class, 'updateQualification'])->name('qualifications.update');
            Route::delete('/qualifications/{qualification}', [TutorWorkspaceController::class, 'destroyQualification'])->name('qualifications.destroy');
            Route::post('/qualifications/{qualification}/evidence', [TutorWorkspaceController::class, 'storeEvidence'])->middleware('throttle:uploads')->name('evidence.store');
            Route::post('/qualifications/{qualification}/submit', [TutorWorkspaceController::class, 'submitQualification'])->name('qualifications.submit');
            Route::get('/evidence/{evidence}', [TutorWorkspaceController::class, 'downloadEvidence'])->name('evidence.show');
            Route::delete('/evidence/{evidence}', [TutorWorkspaceController::class, 'destroyEvidence'])->name('evidence.destroy');

            Route::get('/offerings', [TutorWorkspaceController::class, 'offerings'])->name('offerings');
            Route::post('/offerings', [TutorWorkspaceController::class, 'storeOffering'])->name('offerings.store');
            Route::patch('/offerings/{offering}', [TutorWorkspaceController::class, 'updateOffering'])->name('offerings.update');
            Route::post('/offerings/{offering}/archive', [TutorWorkspaceController::class, 'archiveOffering'])->name('offerings.archive');
            Route::post('/offerings/{offering}/group-sessions', [TutorWorkspaceController::class, 'scheduleGroup'])->middleware('throttle:bookings')->name('offerings.group-sessions');
        });

        // Booking (learner and tutor)
        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{tutorSession}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('/offerings/{offering}/bookings', [BookingController::class, 'store'])->middleware('throttle:bookings')->name('bookings.store');
        Route::post('/offerings/{offering}/series', [BookingController::class, 'storeSeries'])->middleware('throttle:bookings')->name('series.store');
        Route::post('/offerings/{offering}/packages', [BookingController::class, 'storePackage'])->middleware('throttle:bookings')->name('packages.store');
        Route::post('/group-sessions/{tutorSession}/seats', [BookingController::class, 'storeSeat'])->middleware('throttle:bookings')->name('seats.store');

        Route::post('/bookings/{tutorSession}/accept', [BookingController::class, 'accept'])->name('bookings.accept');
        Route::post('/bookings/{tutorSession}/decline', [BookingController::class, 'decline'])->name('bookings.decline');
        Route::post('/bookings/{tutorSession}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::post('/bookings/{tutorSession}/start', [BookingController::class, 'start'])->name('bookings.start');
        Route::post('/bookings/{tutorSession}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
        Route::post('/bookings/{tutorSession}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');
        Route::post('/bookings/{tutorSession}/dispute', [BookingController::class, 'dispute'])->name('bookings.dispute');
        Route::post('/bookings/{tutorSession}/pay', [BookingController::class, 'pay'])->middleware('throttle:bookings')->name('bookings.pay');
        Route::post('/series/{series}/accept', [BookingController::class, 'acceptSeries'])->name('series.accept');
        Route::post('/seats/{seat}/cancel', [BookingController::class, 'cancelSeat'])->name('seats.cancel');
        Route::post('/seats/{seat}/pay', [BookingController::class, 'paySeat'])->middleware('throttle:bookings')->name('seats.pay');
        Route::post('/packages/{purchase}/pay', [BookingController::class, 'payPackage'])->middleware('throttle:bookings')->name('packages.pay');

        // Reviews
        Route::post('/bookings/{tutorSession}/reviews', [ReviewController::class, 'store'])->middleware('throttle:marketplace-reviews')->name('reviews.store');
        Route::post('/reviews/{review}/reply', [ReviewController::class, 'reply'])->middleware('throttle:marketplace-reviews')->name('reviews.reply');
        Route::post('/reviews/{review}/report', [ReviewController::class, 'report'])->middleware('throttle:marketplace-reviews')->name('reviews.report');

        // Operators
        Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
            Route::get('/qualifications', [OperatorController::class, 'qualifications'])->name('qualifications');
            Route::get('/evidence/{evidence}', [OperatorController::class, 'evidence'])->name('evidence');
            Route::post('/qualifications/{qualification}/{decision}', [OperatorController::class, 'decideQualification'])
                ->whereIn('decision', ['verify', 'reject', 'revoke'])->name('qualifications.decide');
            Route::post('/tutors/{tutorProfile}/verification-level', [OperatorController::class, 'setLevel'])->name('tutors.level');
            Route::get('/tutors/{tutorProfile}/verification-history', [OperatorController::class, 'verificationHistory'])->name('tutors.history');
            Route::get('/reviews', [OperatorController::class, 'reviews'])->name('reviews');
            Route::post('/reviews/{review}/moderate', [OperatorController::class, 'moderateReview'])->name('reviews.moderate');
            Route::post('/bookings/{tutorSession}/resolve-dispute', [OperatorController::class, 'resolveDispute'])->name('bookings.resolve');
        });
    });

    // ---------------------------------------------------------------- Pages (minimal; wave 2 builds the real screens)
    Route::post('/t/{tutorProfile}/offerings/{offering}/book', [MarketplacePageController::class, 'book'])->middleware('throttle:bookings')->name('marketplace.book');
    Route::get('/my/bookings', [MarketplacePageController::class, 'bookings'])->name('marketplace.bookings');
    Route::post('/my/bookings/{tutorSession}/{action}', [MarketplacePageController::class, 'act'])
        ->whereIn('action', ['accept', 'decline', 'cancel'])->name('marketplace.bookings.act');
    Route::get('/operator/verification', [MarketplacePageController::class, 'verification'])->middleware('operator')->name('marketplace.operator.verification');
    Route::post('/operator/verification/{qualification}/{decision}', [MarketplacePageController::class, 'decide'])
        ->middleware('operator')->whereIn('decision', ['verify', 'reject'])->name('marketplace.operator.verification.decide');
});
