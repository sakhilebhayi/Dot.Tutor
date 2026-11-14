<?php

use App\Domain\Comms\Http\Controllers\CalendarController;
use App\Domain\Comms\Http\Controllers\InboxController;
use App\Domain\Comms\Http\Controllers\PreferencesController;
use App\Domain\Comms\Http\Controllers\PushController;
use Illuminate\Support\Facades\Route;

// R-47 comms. Rate limiters and listeners live in TutorCommsServiceProvider (route:cache safe).

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'entitled', 'throttle:comms'])
    ->prefix('comms')->name('comms.')->group(function () {
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
        Route::get('/inbox/summary', [InboxController::class, 'summary'])->name('inbox.summary');
        Route::post('/inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
        Route::post('/inbox/{notification}/read', [InboxController::class, 'read'])->whereUuid('notification')->name('inbox.read');

        Route::get('/preferences', [PreferencesController::class, 'show'])->name('preferences');
        Route::put('/preferences', [PreferencesController::class, 'update'])->name('preferences.update');

        Route::get('/push/key', [PushController::class, 'key'])->name('push.key');
        Route::post('/push/subscriptions', [PushController::class, 'subscribe'])->name('push.subscribe');
        Route::delete('/push/subscriptions', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
        Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:comms-push-test')->name('push.test');

        Route::get('/lessons/{session}/calendar.ics', [CalendarController::class, 'lesson'])->whereNumber('session')->name('calendar.lesson');
    });
