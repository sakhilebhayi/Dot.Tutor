<?php

/*
| R-47 operations command centre and guardian views (ops agent, docs/R47_UI_OPS.md). Every operator route is named
| ops.* and classified in App\Domain\Ops\Access\RoutePermissions (OperatorRouteMatrixTest fails on a gap). Every
| action calls the owning domain's service, which writes the safety audit log. Gates, limiters and middleware are
| registered in TutorOpsServiceProvider, never here (route:cache).
*/

use App\Domain\Ops\Http\Controllers\AcademicController;
use App\Domain\Ops\Http\Controllers\AuditController;
use App\Domain\Ops\Http\Controllers\ContentController;
use App\Domain\Ops\Http\Controllers\GuardianController;
use App\Domain\Ops\Http\Controllers\MoneyController;
use App\Domain\Ops\Http\Controllers\OverviewController;
use App\Domain\Ops\Http\Controllers\ReviewsController;
use App\Domain\Ops\Http\Controllers\SafeguardingController;
use App\Domain\Ops\Http\Controllers\SupportController;
use App\Domain\Ops\Http\Controllers\TeamController;
use App\Domain\Ops\Http\Controllers\UsersController;
use App\Domain\Ops\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::middleware('operator')->prefix('ops')->name('ops.')->group(function () {
        Route::get('/', OverviewController::class)->name('home');

        Route::get('/users', [UsersController::class, 'index'])->name('users');
        Route::get('/users/team', [TeamController::class, 'index'])->name('users.team');
        Route::post('/users/team', [TeamController::class, 'store'])->name('users.team.store');
        Route::post('/users/team/{user}', [TeamController::class, 'update'])->whereNumber('user')->name('users.team.update');
        Route::post('/users/team/{user}/remove', [TeamController::class, 'remove'])->whereNumber('user')->name('users.team.remove');
        Route::get('/users/{user}', [UsersController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::post('/users/{user}/restrict', [UsersController::class, 'restrict'])->whereNumber('user')->name('users.restrict');
        Route::post('/users/{user}/age', [UsersController::class, 'age'])->whereNumber('user')->name('users.age');
        Route::post('/users/guardian-links/{link}/revoke', [UsersController::class, 'revokeLink'])->whereNumber('link')->name('users.guardian-links.revoke');

        Route::get('/verification', [VerificationController::class, 'index'])->name('verification');
        Route::get('/verification/tutors/{tutorProfile}', [VerificationController::class, 'tutor'])->whereNumber('tutorProfile')->name('verification.tutor');
        Route::post('/verification/qualifications/{qualification}', [VerificationController::class, 'decide'])->whereNumber('qualification')->name('verification.decide');
        Route::post('/verification/tutors/{tutorProfile}/level', [VerificationController::class, 'level'])->whereNumber('tutorProfile')->name('verification.level');
        Route::post('/verification/tutors/{tutorProfile}/standing', [VerificationController::class, 'standing'])->whereNumber('tutorProfile')->name('verification.standing');

        Route::get('/safeguarding', [SafeguardingController::class, 'queue'])->name('safeguarding');
        Route::get('/safeguarding/reports/{report}', [SafeguardingController::class, 'report'])->whereNumber('report')->name('safeguarding.reports.show');
        Route::post('/safeguarding/reports/{report}', [SafeguardingController::class, 'act'])->whereNumber('report')->name('safeguarding.reports.act');
        Route::get('/safeguarding/incidents', [SafeguardingController::class, 'incidents'])->name('safeguarding.incidents');
        Route::post('/safeguarding/incidents', [SafeguardingController::class, 'openIncident'])->name('safeguarding.incidents.store');
        Route::get('/safeguarding/incidents/{incident}', [SafeguardingController::class, 'incident'])->whereNumber('incident')->name('safeguarding.incidents.show');
        Route::post('/safeguarding/incidents/{incident}', [SafeguardingController::class, 'updateIncident'])->whereNumber('incident')->name('safeguarding.incidents.update');
        Route::get('/safeguarding/integrity', [SafeguardingController::class, 'integrity'])->name('safeguarding.integrity');
        Route::post('/safeguarding/integrity', [SafeguardingController::class, 'openCase'])->name('safeguarding.integrity.store');
        Route::post('/safeguarding/integrity/{case}', [SafeguardingController::class, 'decideCase'])->whereNumber('case')->name('safeguarding.integrity.decide');
        Route::get('/safeguarding/minors', [SafeguardingController::class, 'minors'])->name('safeguarding.minors');
        Route::get('/safeguarding/screening', [SafeguardingController::class, 'screening'])->name('safeguarding.screening');
        Route::post('/safeguarding/screening/{user}', [SafeguardingController::class, 'recordScreening'])->whereNumber('user')->name('safeguarding.screening.store');
        Route::post('/safeguarding/screening/checks/{check}/review', [SafeguardingController::class, 'reviewScreening'])->whereNumber('check')->name('safeguarding.screening.review');
        Route::post('/safeguarding/screening/checks/{check}/revoke', [SafeguardingController::class, 'revokeScreening'])->whereNumber('check')->name('safeguarding.screening.revoke');

        Route::get('/audit', AuditController::class)->name('audit');

        Route::get('/moderation', [ContentController::class, 'index'])->name('moderation');
        Route::post('/moderation/posts/{post}', [ContentController::class, 'decidePost'])->whereNumber('post')->name('moderation.posts.decide');
        Route::post('/moderation/resources/{resource}', [ContentController::class, 'reviewResource'])->whereNumber('resource')->name('moderation.resources.review');
        Route::post('/moderation/complaints/{report}', [ContentController::class, 'decideComplaint'])->whereNumber('report')->name('moderation.complaints.decide');

        Route::get('/reviews', [ReviewsController::class, 'index'])->name('reviews');
        Route::post('/reviews/{review}', [ReviewsController::class, 'moderate'])->whereNumber('review')->name('reviews.moderate');
        Route::get('/reviews/bookings', [ReviewsController::class, 'bookings'])->name('reviews.bookings');
        Route::get('/reviews/bookings/{tutorSession}', [ReviewsController::class, 'booking'])->whereNumber('tutorSession')->name('reviews.booking');
        Route::post('/reviews/bookings/{tutorSession}/resolve', [ReviewsController::class, 'resolve'])->whereNumber('tutorSession')->name('reviews.resolve');

        Route::get('/academic', [AcademicController::class, 'home'])->name('academic');
        Route::get('/academic/subjects', [AcademicController::class, 'subjects'])->name('subjects');
        Route::get('/academic/languages', [AcademicController::class, 'languages'])->name('languages');

        Route::get('/payments', [MoneyController::class, 'payments'])->name('payments');
        Route::get('/payments/orders/{order}', [MoneyController::class, 'order'])->name('payments.order');
        Route::post('/payments/orders/{order}/lines/{line}/refund', [MoneyController::class, 'refund'])->whereNumber('line')->name('payments.refund');
        Route::post('/payments/orders/{order}/lines/{line}/release', [MoneyController::class, 'release'])->whereNumber('line')->name('payments.release');
        Route::post('/payments/issues/{issue}', [MoneyController::class, 'resolveIssue'])->whereNumber('issue')->name('payments.issues.resolve');
        Route::post('/payments/disputes/{dispute}', [MoneyController::class, 'disputeOutcome'])->whereNumber('dispute')->name('payments.disputes.outcome');
        Route::get('/payouts', [MoneyController::class, 'payouts'])->name('payouts');
        Route::get('/payouts/batches/{batch}', [MoneyController::class, 'batch'])->whereNumber('batch')->name('payouts.batch');
        Route::post('/payouts/batches', [MoneyController::class, 'draftBatch'])->name('payouts.draft');
        Route::post('/payouts/{payout}/paid', [MoneyController::class, 'payoutPaid'])->whereNumber('payout')->name('payouts.paid');
        Route::post('/payouts/{payout}/failed', [MoneyController::class, 'payoutFailed'])->whereNumber('payout')->name('payouts.failed');

        Route::get('/support', [SupportController::class, 'index'])->name('support');
        Route::get('/support/{conversation}', [SupportController::class, 'show'])->whereNumber('conversation')->name('support.show');
        Route::post('/support/{conversation}', [SupportController::class, 'reply'])->whereNumber('conversation')->name('support.reply');
    });

    // Guardians (brief section 15): phone-first, in the learner shell. Decisions post to the safety domain.
    Route::get('/guardian', [GuardianController::class, 'home'])->name('guardian.home');
    Route::get('/guardian/children/{learner}', [GuardianController::class, 'child'])->whereNumber('learner')->name('guardian.child');
    Route::get('/guardian/approvals', [GuardianController::class, 'approvals'])->name('guardian.approvals');
    Route::get('/guardian/payments', [GuardianController::class, 'payments'])->name('guardian.payments');
});
