<?php

/*
| R-47 student UI routes (student UI agent, docs/R47_UI_STUDENT.md). Every action here calls the owning domain's
| services; nothing in this file decides a booking, a payment or an enrolment. Rate limiters used here are defined by
| their domains' providers (bookings, marketplace-reviews, academic-describe), never in a route file.
*/

use App\Domain\Student\Http\Controllers\AccountController;
use App\Domain\Student\Http\Controllers\BookController;
use App\Domain\Student\Http\Controllers\CoursesController;
use App\Domain\Student\Http\Controllers\FindController;
use App\Domain\Student\Http\Controllers\HomeController;
use App\Domain\Student\Http\Controllers\LessonController;
use App\Domain\Student\Http\Controllers\OnboardingController;
use App\Domain\Student\Http\Controllers\PracticeController;
use App\Domain\Student\Http\Controllers\ProgressController;
use App\Domain\Student\Http\Controllers\PurchasesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/learn', HomeController::class)->name('learn.home');
    Route::post('/learn/need', [HomeController::class, 'need'])->middleware('throttle:academic-describe')->name('learn.need');

    Route::get('/learn/find', [FindController::class, 'search'])->name('match.search');

    Route::get('/learn/welcome', [OnboardingController::class, 'show'])->name('learn.onboarding');
    Route::post('/learn/welcome', [OnboardingController::class, 'store'])->name('learn.onboarding.store');

    Route::get('/learn/book/{offering}', [BookController::class, 'show'])->whereNumber('offering')->name('learn.book');
    Route::post('/learn/book/{offering}', [BookController::class, 'store'])->whereNumber('offering')->middleware('throttle:bookings')->name('learn.book.store');

    Route::get('/learn/lessons/{tutorSession}', [LessonController::class, 'show'])->name('learn.lessons.show');
    Route::post('/learn/lessons/{tutorSession}/pay', [LessonController::class, 'pay'])->middleware('throttle:bookings')->name('learn.lessons.pay');
    Route::post('/learn/lessons/{tutorSession}/cancel', [LessonController::class, 'cancel'])->name('learn.lessons.cancel');
    Route::post('/learn/lessons/{tutorSession}/review', [LessonController::class, 'review'])->middleware('throttle:marketplace-reviews')->name('learn.lessons.review');

    Route::get('/learn/courses', CoursesController::class)->name('learn.courses');

    Route::get('/learn/practice', [PracticeController::class, 'index'])->name('practice.index');
    Route::post('/learn/practice/cards', [PracticeController::class, 'storeSet'])->name('practice.cards.store');
    Route::get('/learn/practice/cards/{set}', [PracticeController::class, 'review'])->whereNumber('set')->name('practice.cards.show');
    Route::post('/learn/practice/cards/{set}/{card}', [PracticeController::class, 'grade'])->whereNumber(['set', 'card'])->name('practice.cards.grade');

    Route::get('/learn/progress', ProgressController::class)->name('learn.progress');
    Route::get('/learn/purchases', PurchasesController::class)->name('money.orders');

    Route::get('/learn/account', [AccountController::class, 'show'])->name('learn.account');
    Route::put('/learn/account/view', [AccountController::class, 'step'])->name('learn.account.step');
});
