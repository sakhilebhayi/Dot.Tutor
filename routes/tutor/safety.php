<?php

use App\Domain\Safety\Http\Controllers\AccountController;
use App\Domain\Safety\Http\Controllers\ConversationController;
use App\Domain\Safety\Http\Controllers\GuardianController;
use App\Domain\Safety\Http\Controllers\GuardianInvitationController;
use App\Domain\Safety\Http\Controllers\Operator\AccountsController;
use App\Domain\Safety\Http\Controllers\Operator\IncidentController;
use App\Domain\Safety\Http\Controllers\Operator\IntegrityController;
use App\Domain\Safety\Http\Controllers\Operator\ModerationController;
use App\Domain\Safety\Http\Controllers\Operator\ScreeningController;
use App\Domain\Safety\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// R-47 safety domain. Rate limiters, gates and policies live in TutorSafetyServiceProvider (route:cache safe).

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'entitled'])
    ->prefix('safety')->name('safety.')->group(function () {
        Route::get('/me', [AccountController::class, 'show'])->name('account');

        Route::post('/guardians/invitations', [GuardianInvitationController::class, 'store'])
            ->middleware('throttle:safety-invitations')->name('guardians.invite');
        Route::get('/guardians/accept/{invitation}', [GuardianInvitationController::class, 'show'])
            ->middleware('signed')->whereNumber('invitation')->name('guardians.accept');
        Route::post('/guardians/accept/{invitation}', [GuardianInvitationController::class, 'accept'])
            ->middleware('throttle:safety-invitations')->whereNumber('invitation')->name('guardians.accept.store');

        Route::get('/guardian', [GuardianController::class, 'dashboard'])->name('guardian.dashboard');
        Route::post('/guardian/approvals/{approval}', [GuardianController::class, 'decide'])->name('guardian.approvals.decide');
        Route::post('/guardian/links/{link}/revoke', [GuardianController::class, 'revoke'])->name('guardian.links.revoke');
        Route::post('/guardian/links/{link}/recording', [GuardianController::class, 'recording'])->name('guardian.links.recording');
        Route::post('/guardian/links/{link}/approval-settings', [GuardianController::class, 'approvalSettings'])->name('guardian.links.settings');

        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('/conversations', [ConversationController::class, 'store'])
            ->middleware('throttle:safety-messages')->name('conversations.store');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->middleware('throttle:safety-messages')->name('conversations.messages.store');
        Route::get('/attachments/{attachment}', [ConversationController::class, 'attachment'])->name('attachments.show');

        Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:safety-reports')->name('reports.store');
        Route::post('/messages/{message}/report', [ReportController::class, 'message'])
            ->middleware('throttle:safety-reports')->name('messages.report');

        Route::middleware(['operator', 'can:safety.operate'])->prefix('operator')->name('operator.')->group(function () {
            Route::get('/queue', [ModerationController::class, 'queue'])->name('queue');
            Route::get('/reports/{report}', [ModerationController::class, 'show'])->name('reports.show');
            Route::post('/reports/{report}/actions', [ModerationController::class, 'act'])->name('reports.act');
            Route::post('/users/{user}/actions', [ModerationController::class, 'actOnUser'])->name('users.act');

            Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
            Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
            Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
            Route::post('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');

            Route::get('/screening', [ScreeningController::class, 'index'])->name('screening.index');
            Route::get('/users/{user}/screening', [ScreeningController::class, 'forUser'])->name('screening.user');
            Route::post('/users/{user}/screening', [ScreeningController::class, 'store'])->name('screening.store');
            Route::post('/screening/{check}/review', [ScreeningController::class, 'review'])->name('screening.review');
            Route::post('/screening/{check}/revoke', [ScreeningController::class, 'revoke'])->name('screening.revoke');
            Route::get('/screening/{check}/evidence', [ScreeningController::class, 'evidence'])->name('screening.evidence');

            Route::get('/integrity', [IntegrityController::class, 'index'])->name('integrity.index');
            Route::post('/integrity', [IntegrityController::class, 'store'])->name('integrity.store');
            Route::post('/integrity/{case}/decide', [IntegrityController::class, 'decide'])->name('integrity.decide');

            Route::get('/users/{user}', [AccountsController::class, 'show'])->name('users.show');
            Route::post('/users/{user}/age', [AccountsController::class, 'age'])->name('users.age');
            Route::post('/guardian-links/{link}/revoke', [AccountsController::class, 'revokeLink'])->name('guardian-links.revoke');
            Route::get('/audit', [AccountsController::class, 'audit'])->name('audit');
        });
    });
