<?php

use App\Domain\Security\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

// R-47 security: the data subject's own data and account (docs/R47_PRIVACY.md). Aliases and limiters live in
// TutorSecurityServiceProvider (route:cache).
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->prefix('account')->name('security.')->group(function () {
    Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');
    Route::get('/data-export', [PrivacyController::class, 'export'])->middleware(['tutor.recent-auth', 'throttle:tutor-data-export'])->name('export');
    Route::post('/close', [PrivacyController::class, 'close'])->middleware(['tutor.recent-auth', 'throttle:6,1'])->name('close');
});
