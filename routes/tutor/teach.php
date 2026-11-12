<?php

/*
| R-47 tutor workspace routes (tutor UI agent, D-1200 onward). Screens only: every action calls the owning domain's
| service, which enforces its rules. Names under teach.* light up the workspace shell (config/tutor_nav.php).
| Rate limiters are defined in TutorTeachServiceProvider, never here (route:cache).
*/

use App\Domain\Teach\Http\Controllers\BookingsController;
use App\Domain\Teach\Http\Controllers\CalendarController;
use App\Domain\Teach\Http\Controllers\CoursesController;
use App\Domain\Teach\Http\Controllers\HomeController;
use App\Domain\Teach\Http\Controllers\MaterialsController;
use App\Domain\Teach\Http\Controllers\MoneyController;
use App\Domain\Teach\Http\Controllers\OfferingsController;
use App\Domain\Teach\Http\Controllers\OnboardingController;
use App\Domain\Teach\Http\Controllers\StorefrontController;
use App\Domain\Teach\Http\Controllers\StudentsController;
use App\Domain\Teach\Http\Middleware\RequireTutorProfile;
use Illuminate\Support\Facades\Route;

// A tutor's short share link (/u/{handle}) opens their storefront.
Route::get('/u/{handle}', [StorefrontController::class, 'handle'])
    ->where('handle', '[a-z0-9-]{3,60}')
    ->middleware('throttle:teach-referrals')
    ->name('teach.handle');

// A tutor's referral link: counts a signed-in visit (attribution only) and opens the storefront. Inside the
// sign-in group so a visitor who signs in first comes back here and is counted (the storefront needs sign-in).
Route::get('/r/{code}', [StorefrontController::class, 'referral'])
    ->where('code', '[a-z0-9]{6,16}')
    ->middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'throttle:teach-referrals'])
    ->name('teach.referral');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->prefix('teach')->name('teach.')->group(function () {
    // Becoming a tutor: the guided onboarding works before a profile exists.
    Route::get('/profile', [OnboardingController::class, 'show'])->name('profile');
    Route::post('/profile/start', [OnboardingController::class, 'start'])->name('profile.start');

    Route::middleware(RequireTutorProfile::class)->group(function () {
        Route::put('/profile/about', [OnboardingController::class, 'about'])->name('profile.about');
        Route::post('/profile/photo', [OnboardingController::class, 'photo'])->middleware('throttle:uploads')->name('profile.photo');
        Route::delete('/profile/photo', [OnboardingController::class, 'removePhoto'])->name('profile.photo.destroy');
        Route::put('/profile/scope', [OnboardingController::class, 'scope'])->name('profile.scope');
        Route::put('/profile/languages', [OnboardingController::class, 'languages'])->name('profile.languages');
        Route::post('/profile/qualifications', [OnboardingController::class, 'storeQualification'])->name('profile.qualifications.store');
        Route::delete('/profile/qualifications/{qualification}', [OnboardingController::class, 'destroyQualification'])->name('profile.qualifications.destroy');
        Route::post('/profile/qualifications/{qualification}/evidence', [OnboardingController::class, 'storeEvidence'])->middleware('throttle:uploads')->name('profile.evidence.store');
        Route::delete('/profile/evidence/{evidence}', [OnboardingController::class, 'destroyEvidence'])->name('profile.evidence.destroy');
        Route::post('/profile/qualifications/{qualification}/submit', [OnboardingController::class, 'submitQualification'])->name('profile.qualifications.submit');
        Route::put('/profile/safeguarding', [OnboardingController::class, 'safeguarding'])->name('profile.safeguarding');
        Route::post('/profile/submit', [OnboardingController::class, 'submit'])->name('profile.submit');

        Route::get('/', HomeController::class)->name('home');

        Route::get('/bookings', [BookingsController::class, 'index'])->name('bookings');
        Route::post('/bookings/{tutorSession}/{action}', [BookingsController::class, 'act'])
            ->whereIn('action', ['accept', 'decline', 'cancel', 'start', 'complete', 'no-show'])->name('bookings.act');
        Route::post('/series/{series}/accept', [BookingsController::class, 'acceptSeries'])->name('bookings.series.accept');
        Route::post('/offerings/{offering}/group-lessons', [BookingsController::class, 'scheduleGroup'])->middleware('throttle:bookings')->name('bookings.group');

        Route::get('/calendar', CalendarController::class)->name('calendar');
        Route::post('/calendar/hours', [CalendarController::class, 'storeWindow'])->name('calendar.hours.store');
        Route::delete('/calendar/hours/{window}', [CalendarController::class, 'destroyWindow'])->whereNumber('window')->name('calendar.hours.destroy');
        Route::post('/calendar/days-off', [CalendarController::class, 'storeBlocked'])->name('calendar.off.store');
        Route::delete('/calendar/days-off/{blockedDate}', [CalendarController::class, 'destroyBlocked'])->whereNumber('blockedDate')->name('calendar.off.destroy');

        Route::get('/students', [StudentsController::class, 'index'])->name('students');
        Route::get('/students/{learner}', [StudentsController::class, 'show'])->whereNumber('learner')->name('students.show');
        Route::put('/students/{learner}/note', [StudentsController::class, 'note'])->whereNumber('learner')->name('students.note');

        Route::get('/offerings', [OfferingsController::class, 'index'])->name('offerings');
        Route::get('/offerings/new', [OfferingsController::class, 'create'])->name('offerings.create');
        Route::post('/offerings', [OfferingsController::class, 'store'])->name('offerings.store');
        Route::get('/offerings/{offering}/edit', [OfferingsController::class, 'edit'])->name('offerings.edit');
        Route::put('/offerings/{offering}', [OfferingsController::class, 'update'])->name('offerings.update');
        Route::post('/offerings/{offering}/archive', [OfferingsController::class, 'archive'])->name('offerings.archive');
        Route::post('/offerings/{offering}/restore', [OfferingsController::class, 'restore'])->name('offerings.restore');
        Route::post('/offerings/price-preview', [OfferingsController::class, 'preview'])->name('offerings.preview');
        Route::post('/coupons', [OfferingsController::class, 'storeCoupon'])->name('offerings.coupons.store');
        Route::post('/coupons/{coupon}/deactivate', [OfferingsController::class, 'deactivateCoupon'])->name('offerings.coupons.deactivate');

        Route::get('/storefront', [StorefrontController::class, 'edit'])->name('storefront');
        Route::put('/storefront', [StorefrontController::class, 'update'])->name('storefront.update');
        Route::get('/storefront/promo', [StorefrontController::class, 'promo'])->name('storefront.promo');

        Route::get('/courses', [CoursesController::class, 'index'])->name('courses');
        Route::get('/courses/new', [CoursesController::class, 'create'])->name('courses.create');
        Route::post('/courses', [CoursesController::class, 'store'])->name('courses.store');
        Route::get('/courses/{course}', [CoursesController::class, 'show'])->whereNumber('course')->name('courses.show');
        Route::put('/courses/{course}/details', [CoursesController::class, 'details'])->whereNumber('course')->name('courses.details');
        Route::post('/courses/{course}/quizzes', [CoursesController::class, 'storeQuiz'])->whereNumber('course')->name('courses.quizzes.store');
        Route::post('/course-bundles', [CoursesController::class, 'storeBundle'])->name('courses.bundles.store');

        Route::get('/materials', [MaterialsController::class, 'index'])->name('materials');
        Route::post('/materials', [MaterialsController::class, 'upload'])->middleware('throttle:learning-uploads')->name('materials.store');
        Route::get('/materials/assessments', [MaterialsController::class, 'assessments'])->name('materials.assessments');
        Route::post('/materials/banks', [MaterialsController::class, 'storeBank'])->name('materials.banks.store');
        Route::get('/materials/banks/{bank}', [MaterialsController::class, 'bank'])->whereNumber('bank')->name('materials.banks.show');
        Route::post('/materials/banks/{bank}/questions', [MaterialsController::class, 'storeQuestion'])->whereNumber('bank')->name('materials.questions.store');
        Route::post('/materials/questions/{question}/retire', [MaterialsController::class, 'retireQuestion'])->whereNumber('question')->name('materials.questions.retire');
        Route::post('/materials/assessments', [MaterialsController::class, 'storeAssessment'])->name('materials.assessments.store');
        Route::get('/materials/assessments/{assessment}', [MaterialsController::class, 'assessment'])->whereNumber('assessment')->name('materials.assessments.show');
        Route::post('/materials/assessments/{assessment}/questions', [MaterialsController::class, 'attach'])->whereNumber('assessment')->name('materials.assessments.attach');
        Route::post('/materials/assessments/{assessment}/assign', [MaterialsController::class, 'assign'])->whereNumber('assessment')->name('materials.assessments.assign');

        Route::get('/earnings', [MoneyController::class, 'earnings'])->name('earnings');
        Route::get('/earnings/export', [MoneyController::class, 'export'])->middleware('throttle:teach-exports')->name('earnings.export');
        Route::get('/payouts', [MoneyController::class, 'payouts'])->name('payouts');
        Route::get('/payouts/{payout}', [MoneyController::class, 'payout'])->whereNumber('payout')->name('payouts.show');
        Route::put('/payouts/account', [MoneyController::class, 'account'])->name('payouts.account');
        Route::get('/insights', [MoneyController::class, 'insights'])->name('insights');
    });
});
