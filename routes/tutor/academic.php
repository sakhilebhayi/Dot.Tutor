<?php

use App\Domain\Academic\Http\Controllers\DescribeNeedController;
use App\Domain\Academic\Http\Controllers\LearnerProfileController;
use App\Domain\Academic\Http\Controllers\LocaleController;
use App\Domain\Academic\Http\Controllers\Operator\AcademicResourceController;
use App\Domain\Academic\Http\Controllers\Operator\TeachingLanguageReviewController;
use App\Domain\Academic\Http\Controllers\StructureController;
use App\Domain\Academic\Http\Controllers\TranslationController;
use App\Domain\Academic\Http\Operator\OperatorResources;
use Illuminate\Support\Facades\Route;

/*
 * R-47 academic domain routes (owner: academic agent). Rate limiters live in
 * TutorAcademicServiceProvider::boot(), never here (route:cache).
 */

// The interface-language switcher works for guests too.
Route::get('/language', [LocaleController::class, 'index'])->name('academic.locale.index');
Route::post('/language', [LocaleController::class, 'update'])->middleware('throttle:20,1')->name('academic.locale.update');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('/learn/profile', [LearnerProfileController::class, 'edit'])->name('academic.profile.edit');
    Route::put('/learn/profile', [LearnerProfileController::class, 'update'])->name('academic.profile.update');
    Route::post('/learn/describe', DescribeNeedController::class)->middleware('throttle:academic-describe')->name('academic.describe');

    Route::get('/academic/curricula', [StructureController::class, 'curricula'])->name('academic.curricula');
    Route::get('/academic/levels/{level}/offerings', [StructureController::class, 'offerings'])->whereNumber('level')->name('academic.offerings');
    Route::get('/academic/offerings/{offering}/topics', [StructureController::class, 'topics'])->whereNumber('offering')->name('academic.topics');

    Route::get('/translations/{type}/{id}/{field}', [TranslationController::class, 'show'])
        ->where(['type' => '[a-z_]+\.[a-z_]+', 'id' => '[0-9]+', 'field' => '[a-z_]+'])
        ->name('academic.translations.show');

    Route::middleware('operator')->prefix('operator/academic')->name('operator.academic.')->group(function () {
        Route::get('/', [AcademicResourceController::class, 'home'])->name('home');

        Route::get('/teaching-languages', [TeachingLanguageReviewController::class, 'index'])->name('teaching-languages.index');
        Route::post('/teaching-languages/{userLanguage}/verify', [TeachingLanguageReviewController::class, 'verify'])->name('teaching-languages.verify');
        Route::post('/teaching-languages/{userLanguage}/unverify', [TeachingLanguageReviewController::class, 'unverify'])->name('teaching-languages.unverify');

        $resources = implode('|', OperatorResources::keys());
        Route::get('/{resource}', [AcademicResourceController::class, 'index'])->where('resource', $resources)->name('index');
        Route::post('/{resource}', [AcademicResourceController::class, 'store'])->where('resource', $resources)->name('store');
        Route::put('/{resource}/{id}', [AcademicResourceController::class, 'update'])->where(['resource' => $resources, 'id' => '[0-9]+'])->name('update');
        Route::post('/{resource}/{id}/toggle', [AcademicResourceController::class, 'toggle'])->where(['resource' => $resources, 'id' => '[0-9]+'])->name('toggle');
    });
});
