<?php

/*
| R-47 learning domain routes (owner: learning agent). Every action answers both the web screens under /learning and
| the JSON API under /api/tutor/learning (session-authenticated, CSRF-protected). Rate limiters, policies and the
| private disk are registered in TutorLearningServiceProvider, never here.
*/

use App\Domain\Learning\Http\Controllers\AssessmentController;
use App\Domain\Learning\Http\Controllers\AttemptController;
use App\Domain\Learning\Http\Controllers\CatalogueController;
use App\Domain\Learning\Http\Controllers\CertificateController;
use App\Domain\Learning\Http\Controllers\CourseReviewController;
use App\Domain\Learning\Http\Controllers\CourseStudioController;
use App\Domain\Learning\Http\Controllers\FlashcardController;
use App\Domain\Learning\Http\Controllers\LearnController;
use App\Domain\Learning\Http\Controllers\LibraryController;
use App\Domain\Learning\Http\Controllers\LibraryModerationController;
use App\Domain\Learning\Http\Controllers\ProgressController;
use Illuminate\Support\Facades\Route;

// Public: anyone holding a certificate code can verify it.
Route::get('/certificates/{code}', [CertificateController::class, 'verify'])
    ->where('code', '[A-Za-z0-9-]{4,32}')
    ->middleware('throttle:30,1')
    ->name('learning.certificates.verify');

$learningRoutes = function (): void {
    // Course studio (creator).
    Route::get('studio/courses', [CourseStudioController::class, 'index'])->name('studio.courses.index');
    Route::post('studio/courses', [CourseStudioController::class, 'store'])->name('studio.courses.store');
    Route::get('studio/courses/{course}', [CourseStudioController::class, 'show'])->name('studio.courses.show');
    Route::put('studio/courses/{course}', [CourseStudioController::class, 'update'])->name('studio.courses.update');
    Route::post('studio/courses/{course}/publish', [CourseStudioController::class, 'publish'])->name('studio.courses.publish');
    Route::post('studio/courses/{course}/archive', [CourseStudioController::class, 'archive'])->name('studio.courses.archive');
    Route::get('studio/courses/{course}/learners', [CourseStudioController::class, 'learners'])->name('studio.courses.learners');
    Route::post('studio/courses/{course}/sections', [CourseStudioController::class, 'storeSection'])->name('studio.sections.store');
    Route::put('studio/sections/{section}', [CourseStudioController::class, 'updateSection'])->name('studio.sections.update');
    Route::delete('studio/sections/{section}', [CourseStudioController::class, 'destroySection'])->name('studio.sections.destroy');
    Route::post('studio/sections/{section}/lessons', [CourseStudioController::class, 'storeLesson'])->middleware('throttle:learning-uploads')->name('studio.lessons.store');
    Route::post('studio/lessons/{lesson}', [CourseStudioController::class, 'updateLesson'])->middleware('throttle:learning-uploads')->name('studio.lessons.update');
    Route::delete('studio/lessons/{lesson}', [CourseStudioController::class, 'destroyLesson'])->name('studio.lessons.destroy');
    Route::get('studio/lessons/{lesson}/preview', [LearnController::class, 'preview'])->name('studio.lessons.preview');
    Route::post('studio/courses/{course}/materials', [CourseStudioController::class, 'storeMaterial'])->middleware('throttle:learning-uploads')->name('studio.materials.store');
    Route::delete('studio/materials/{material}', [CourseStudioController::class, 'destroyMaterial'])->name('studio.materials.destroy');
    Route::post('studio/bundles', [CourseStudioController::class, 'storeBundle'])->name('studio.bundles.store');

    // Catalogue, purchase hand-off, enrolment.
    Route::get('courses', [CatalogueController::class, 'index'])->name('courses.index');
    Route::get('courses/{course}', [CatalogueController::class, 'show'])->name('courses.show');
    Route::get('courses/{course}/quote', [CatalogueController::class, 'quote'])->name('courses.quote');
    Route::post('courses/{course}/enrol', [CatalogueController::class, 'enrol'])->name('courses.enrol');
    Route::post('courses/{course}/checkout', [CatalogueController::class, 'checkoutCourse'])->middleware('throttle:10,1')->name('courses.checkout');
    Route::get('bundles/{bundle}', [CatalogueController::class, 'showBundle'])->name('bundles.show');
    Route::post('bundles/{bundle}/checkout', [CatalogueController::class, 'checkoutBundle'])->middleware('throttle:10,1')->name('bundles.checkout');

    // Protected delivery and progress.
    Route::get('courses/{course}/learn/{lesson?}', [LearnController::class, 'learn'])->whereNumber('lesson')->name('learn');
    Route::post('courses/{course}/lessons/{lesson}/complete', [LearnController::class, 'complete'])->whereNumber('lesson')->name('lessons.complete');
    Route::post('courses/{course}/lessons/{lesson}/position', [LearnController::class, 'position'])->whereNumber('lesson')->name('lessons.position');
    Route::get('courses/{course}/progress', [LearnController::class, 'progressSummary'])->name('courses.progress');
    Route::post('courses/{course}/upgrade', [LearnController::class, 'upgrade'])->name('courses.upgrade');
    Route::get('courses/{course}/materials/{material}', [LearnController::class, 'material'])->whereNumber('material')->name('materials.show');
    Route::get('courses/{course}/reviews', [CourseReviewController::class, 'index'])->name('courses.reviews.index');
    Route::post('courses/{course}/reviews', [CourseReviewController::class, 'store'])->middleware('throttle:learning-reports')->name('courses.reviews.store');
    Route::get('certificates', [CertificateController::class, 'index'])->name('certificates.index');
    Route::post('certificates/{certificate}/name', [CertificateController::class, 'updateName'])->name('certificates.name');

    // Resource library.
    Route::get('library', [LibraryController::class, 'index'])->name('library.index');
    Route::post('library', [LibraryController::class, 'store'])->middleware('throttle:learning-uploads')->name('library.store');
    Route::get('library/moderation', [LibraryModerationController::class, 'queue'])->name('library.moderation');
    Route::get('library/{resource}', [LibraryController::class, 'show'])->whereNumber('resource')->name('library.show');
    Route::get('library/{resource}/download', [LibraryController::class, 'download'])->name('library.download');
    Route::match(['post', 'delete'], 'library/{resource}/recommend', [LibraryController::class, 'recommend'])->name('library.recommend');
    Route::post('library/{resource}/reports', [LibraryController::class, 'report'])->middleware('throttle:learning-reports')->name('library.reports.store');
    Route::post('library/{resource}/withdraw', [LibraryController::class, 'withdraw'])->name('library.withdraw');
    Route::post('library/{resource}/checkout', [LibraryController::class, 'checkout'])->middleware('throttle:10,1')->name('library.checkout');
    Route::post('library/{resource}/reviews', [LibraryModerationController::class, 'review'])->name('library.reviews.store');
    Route::post('library/reports/{report}/counter', [LibraryController::class, 'counterNotice'])->name('library.reports.counter');
    Route::post('library/reports/{report}/decide', [LibraryModerationController::class, 'decide'])->name('library.reports.decide');

    // Question banks, assessments, attempts.
    Route::get('banks', [AssessmentController::class, 'banks'])->name('banks.index');
    Route::post('banks', [AssessmentController::class, 'storeBank'])->name('banks.store');
    Route::get('banks/{bank}', [AssessmentController::class, 'showBank'])->name('banks.show');
    Route::post('banks/{bank}/questions', [AssessmentController::class, 'storeQuestion'])->name('banks.questions.store');
    Route::post('questions/{question}/retire', [AssessmentController::class, 'retireQuestion'])->name('questions.retire');
    Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
    Route::post('assessments', [AssessmentController::class, 'store'])->name('assessments.store');
    Route::get('assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show');
    Route::post('assessments/{assessment}/questions', [AssessmentController::class, 'attachQuestions'])->name('assessments.questions');
    Route::post('assessments/{assessment}/assign', [AssessmentController::class, 'assign'])->name('assessments.assign');
    Route::get('assessments/{assessment}/attempts', [AssessmentController::class, 'attempts'])->name('assessments.attempts.index');
    Route::post('assessments/{assessment}/attempts', [AttemptController::class, 'start'])->middleware('throttle:learning-attempts')->name('assessments.attempts.store');
    Route::get('assessments/{assessment}/take', [AttemptController::class, 'take'])->name('assessments.take');
    Route::post('attempts/{attempt}/submit', [AttemptController::class, 'submit'])->middleware('throttle:learning-attempts')->name('attempts.submit');
    Route::get('attempts/{attempt}', [AttemptController::class, 'show'])->name('attempts.show');

    // Flashcards and spaced review.
    Route::get('flashcards', [FlashcardController::class, 'index'])->name('flashcards.index');
    Route::post('flashcards', [FlashcardController::class, 'store'])->name('flashcards.store');
    Route::get('flashcards/{set}/due', [FlashcardController::class, 'due'])->name('flashcards.due');
    Route::post('flashcards/cards/{card}/review', [FlashcardController::class, 'review'])->name('flashcards.review');

    // Mastery, insights, recent activity.
    Route::get('progress', ProgressController::class)->name('progress');
};

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', 'entitled'])->group(function () use ($learningRoutes) {
    Route::prefix('learning')->name('learning.')->group(function () use ($learningRoutes) {
        $learningRoutes();
        // Signed, user-bound media URLs (video with range requests, images, documents, slides).
        Route::get('media/{course}/{kind}/{item}', [LearnController::class, 'media'])
            ->whereIn('kind', ['lesson'])->whereNumber('item')
            ->middleware('signed')
            ->name('media');
    });
    Route::prefix('api/tutor/learning')->name('api.learning.')->group($learningRoutes);
});
