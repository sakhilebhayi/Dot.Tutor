<?php

/*
 * R-47 integration routes (integration agent). Only what no single domain owns: a tutor's teaching languages live
 * in the academic language profile but are edited from the marketplace workspace.
 */

use App\Domain\Integration\Http\TeachingLanguagesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->prefix('api/tutor/me')->name('integration.')->group(function () {
    Route::get('/teaching-languages', [TeachingLanguagesController::class, 'show'])->name('teaching-languages.show');
    Route::put('/teaching-languages', [TeachingLanguagesController::class, 'update'])->name('teaching-languages.update');
});
