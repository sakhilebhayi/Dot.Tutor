<?php

/*
| R-47 community routes (owner: community agent). Every action answers both the learner screens under /rooms and
| the JSON API under /api/tutor/community (session-authenticated, CSRF-protected). Rate limiters, gates and
| bindings live in TutorCommunityServiceProvider, never here.
*/

use App\Domain\Community\Http\Controllers\GroupController;
use App\Domain\Community\Http\Controllers\ModerationController;
use App\Domain\Community\Http\Controllers\PostController;
use App\Domain\Community\Http\Controllers\RoomController;
use App\Domain\Community\Http\Controllers\TutorRoomController;
use Illuminate\Support\Facades\Route;

$communityRoutes = function (): void {
    Route::get('/', [RoomController::class, 'index'])->name('index');
    Route::get('me/indicators', [RoomController::class, 'indicators'])->name('indicators');
    Route::get('levels/{level}', [RoomController::class, 'level'])->whereNumber('level')->name('levels.show');
    Route::get('levels/{level}/subjects/{offering}', [RoomController::class, 'open'])->whereNumber(['level', 'offering'])->name('open');

    Route::middleware('throttle:community-writes')->group(function () {
        Route::post('tutor-rooms', [TutorRoomController::class, 'store'])->name('tutor-rooms.store');
        Route::post('{room}/join', [RoomController::class, 'join'])->whereNumber('room')->name('join');
        Route::post('{room}/leave', [RoomController::class, 'leave'])->whereNumber('room')->name('leave');
        Route::post('{room}/enrol', [TutorRoomController::class, 'enrol'])->whereNumber('room')->name('enrol');
        Route::post('{room}/members/{member}/decide', [TutorRoomController::class, 'decide'])->whereNumber(['room', 'member'])->name('members.decide');
        Route::delete('{room}/members/{member}', [TutorRoomController::class, 'removeMember'])->whereNumber(['room', 'member'])->name('members.remove');
        Route::post('{room}/archive', [TutorRoomController::class, 'archive'])->whereNumber('room')->name('archive');
        Route::post('{room}/invite-code', [TutorRoomController::class, 'inviteCode'])->whereNumber('room')->name('invite-code');
        Route::post('{room}/sessions', [TutorRoomController::class, 'schedule'])->whereNumber('room')->name('sessions.store');

        Route::post('{room}/posts', [PostController::class, 'store'])->whereNumber('room')->name('posts.store');
        Route::post('posts/{post}/replies', [PostController::class, 'reply'])->whereNumber('post')->name('posts.reply');
        Route::put('posts/{post}', [PostController::class, 'update'])->whereNumber('post')->name('posts.update');
        Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereNumber('post')->name('posts.destroy');
        Route::post('posts/{post}/accept', [PostController::class, 'accept'])->whereNumber('post')->name('posts.accept');
        Route::post('posts/{post}/resolve', [PostController::class, 'resolve'])->whereNumber('post')->name('posts.resolve');
        Route::post('posts/{post}/report', [PostController::class, 'report'])->whereNumber('post')->middleware('throttle:safety-reports')->name('posts.report');

        Route::post('{room}/groups', [GroupController::class, 'store'])->whereNumber('room')->name('groups.store');
        Route::post('groups/{group}/join', [GroupController::class, 'join'])->whereNumber('group')->name('groups.join');
        Route::post('groups/{group}/leave', [GroupController::class, 'leave'])->whereNumber('group')->name('groups.leave');
        Route::post('groups/{group}/close', [GroupController::class, 'close'])->whereNumber('group')->name('groups.close');
        Route::post('groups/{group}/members/{member}/decide', [GroupController::class, 'decide'])->whereNumber(['group', 'member'])->name('groups.members.decide');
        Route::delete('groups/{group}/members/{member}', [GroupController::class, 'removeMember'])->whereNumber(['group', 'member'])->name('groups.members.remove');
        Route::post('groups/{group}/sessions', [GroupController::class, 'schedule'])->whereNumber('group')->name('groups.sessions.store');
        Route::post('groups/{group}/resources', [GroupController::class, 'shareResource'])->whereNumber('group')->name('groups.resources.store');
        Route::post('groups/{group}/revision', [GroupController::class, 'addRevisionItem'])->whereNumber('group')->name('groups.revision.store');
        Route::post('groups/{group}/tasks', [GroupController::class, 'addTask'])->whereNumber('group')->name('groups.tasks.store');
        Route::post('revision/{item}/tick', [GroupController::class, 'tick'])->whereNumber('item')->name('revision.tick');
        Route::post('tasks/{task}/mark', [GroupController::class, 'markTask'])->whereNumber('task')->name('tasks.mark');
        Route::post('sessions/{session}/cancel', [GroupController::class, 'cancelSession'])->whereNumber('session')->name('sessions.cancel');
        Route::post('sessions/{session}/attendance', [GroupController::class, 'attendance'])->whereNumber('session')->name('sessions.attendance');

        Route::post('moderation/posts/{post}', [ModerationController::class, 'decide'])->whereNumber('post')->middleware('can:community.moderate')->name('moderation.decide');
    });

    Route::get('moderation', [ModerationController::class, 'index'])->middleware('can:community.moderate')->name('moderation');
    Route::get('posts/{post}', [PostController::class, 'show'])->whereNumber('post')->name('posts.show');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->whereNumber('post')->name('posts.edit');
    Route::get('posts/{post}/history', [PostController::class, 'history'])->whereNumber('post')->name('posts.history');
    Route::get('groups/{group}', [GroupController::class, 'show'])->whereNumber('group')->name('groups.show');
    Route::get('sessions/{session}/calendar.ics', [GroupController::class, 'calendar'])->whereNumber('session')->name('sessions.calendar');
    Route::get('{room}', [RoomController::class, 'show'])->whereNumber('room')->name('show');
    Route::get('{room}/ask', [PostController::class, 'create'])->whereNumber('room')->name('ask');
    Route::get('{room}/activity', [RoomController::class, 'activity'])->whereNumber('room')->middleware('throttle:community-polls')->name('activity');
};

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'entitled'])->group(function () use ($communityRoutes) {
    Route::prefix('rooms')->name('rooms.')->group($communityRoutes);
    Route::prefix('api/tutor/community')->name('community.api.')->group($communityRoutes);

    // The tutor's own rooms, in the workspace shell.
    Route::get('teach/rooms', [TutorRoomController::class, 'index'])->name('teach.rooms');
});
