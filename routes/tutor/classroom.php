<?php

/*
 * R-47 live classroom routes (classroom agent, D-800 onward). Pages under /lessons/{id}/classroom, the room's JSON API
 * under /lessons/{id}/classroom/api. Rate limiters are defined in TutorClassroomServiceProvider, never here.
 */

use App\Domain\Classroom\Http\Controllers\ClassroomPageController;
use App\Domain\Classroom\Http\Controllers\RoomApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/classroom', [ClassroomPageController::class, 'index'])->name('classroom.index');

    Route::prefix('lessons/{tutorSession}/classroom')->name('classroom.')->group(function () {
        Route::get('/', [ClassroomPageController::class, 'show'])->name('show');
        Route::get('/after', [ClassroomPageController::class, 'after'])->name('after');

        Route::middleware('throttle:classroom-actions')->group(function () {
            Route::post('/link', [ClassroomPageController::class, 'fallbackLink'])->name('link');
            Route::post('/approval', [ClassroomPageController::class, 'requestApproval'])->name('approval');
            Route::post('/notes', [ClassroomPageController::class, 'tutorNotes'])->name('notes');
            Route::post('/summary', [ClassroomPageController::class, 'summary'])->name('summary');
            Route::post('/summary/draft', [ClassroomPageController::class, 'draftSummary'])->name('summary.draft');
            Route::post('/complete', [ClassroomPageController::class, 'complete'])->name('complete');
            Route::post('/follow-up', [ClassroomPageController::class, 'assign'])->name('follow-up');
        });

        Route::get('/files/{resource}', [RoomApiController::class, 'file'])->name('files.show');

        Route::prefix('api')->name('api.')->group(function () {
            Route::post('/poll', [RoomApiController::class, 'poll'])->middleware('throttle:classroom-poll')->name('poll');
            Route::post('/signal', [RoomApiController::class, 'signal'])->middleware('throttle:classroom-signal')->name('signal');
            Route::post('/board', [RoomApiController::class, 'board'])->middleware('throttle:classroom-board')->name('board');
            Route::middleware('throttle:classroom-actions')->group(function () {
                Route::post('/join', [RoomApiController::class, 'join'])->name('join');
                Route::post('/leave', [RoomApiController::class, 'leave'])->name('leave');
                Route::post('/tutor', [RoomApiController::class, 'tutorAction'])->name('tutor');
                Route::post('/end', [RoomApiController::class, 'end'])->name('end');
                Route::post('/chat', [RoomApiController::class, 'chat'])->name('chat');
                Route::post('/chat/{message}/report', [RoomApiController::class, 'reportMessage'])->name('report');
                Route::post('/board/page', [RoomApiController::class, 'boardPage'])->name('board.page');
                Route::post('/board/snapshot', [RoomApiController::class, 'boardSnapshot'])->name('board.snapshot');
                Route::post('/notes', [RoomApiController::class, 'notes'])->name('notes');
                Route::post('/files', [RoomApiController::class, 'upload'])->name('upload');
            });
        });
    });
});
