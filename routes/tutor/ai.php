<?php

/*
| R-47 AI assistant routes (owner: ai agent). Every action answers the web screens under /ai and the JSON API under
| /api/tutor/ai (session-authenticated, CSRF-protected). The rate limiter and the review gate are defined in
| TutorAiServiceProvider, never here.
*/

use App\Domain\Ai\Http\Controllers\AssistantController;
use App\Domain\Ai\Http\Controllers\NeedController;
use App\Domain\Ai\Http\Controllers\OperatorLogController;
use App\Domain\Ai\Http\Controllers\TranslationAssistController;
use App\Domain\Ai\Http\Controllers\TutorAssistController;
use Illuminate\Support\Facades\Route;

$aiRoutes = function (): void {
    Route::get('/', [AssistantController::class, 'index'])->name('index');
    Route::post('ask', [AssistantController::class, 'ask'])->middleware('throttle:ai-requests')->name('ask');
    Route::get('interactions/{interaction}', [AssistantController::class, 'show'])->whereNumber('interaction')->name('interactions.show');
    Route::post('interactions/{interaction}/report', [AssistantController::class, 'report'])->whereNumber('interaction')->name('interactions.report');

    Route::get('tutor', [TutorAssistController::class, 'index'])->name('tutor.index');
    Route::post('tutor/ask', [TutorAssistController::class, 'ask'])->middleware('throttle:ai-requests')->name('tutor.ask');
    Route::post('drafts/{draft}/accept', [TutorAssistController::class, 'accept'])->whereNumber('draft')->name('drafts.accept');
    Route::post('drafts/{draft}/discard', [TutorAssistController::class, 'discard'])->whereNumber('draft')->name('drafts.discard');

    Route::post('translations', [TranslationAssistController::class, 'store'])->middleware('throttle:ai-requests')->name('translations.store');
    Route::get('translations/{type}/{id}/{field}', [TranslationAssistController::class, 'show'])
        ->where(['type' => '[a-z_]+\.[a-z_]+', 'field' => '[a-z_]+'])->whereNumber('id')->name('translations.show');
    Route::post('translations/{translation}/review', [TranslationAssistController::class, 'review'])->whereNumber('translation')->name('translations.review');

    Route::get('describe', [NeedController::class, 'form'])->name('need.form');
    Route::post('describe', [NeedController::class, 'describe'])->middleware('throttle:ai-requests')->name('need.describe');
    Route::post('describe/{interaction}/confirm', [NeedController::class, 'confirm'])->whereNumber('interaction')->name('need.confirm');

    Route::get('operator/log', [OperatorLogController::class, 'index'])->middleware('operator')->name('operator.log');
};

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () use ($aiRoutes) {
    Route::prefix('ai')->name('ai.')->group($aiRoutes);
    Route::prefix('api/tutor/ai')->name('api.ai.')->group($aiRoutes);
});
