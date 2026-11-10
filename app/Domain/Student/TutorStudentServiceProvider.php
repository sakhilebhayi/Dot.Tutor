<?php

namespace App\Domain\Student;

use App\Domain\Student\Http\Composers\FindComposer;
use App\Domain\Student\Http\Composers\LearnerComposer;
use App\Domain\Student\Http\Composers\LessonsComposer;
use App\Domain\Student\Http\Composers\StorefrontComposer;
use App\Domain\Student\Services\LearnerContext;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 student UI (decisions D-1100 onward, docs/R47_UI_STUDENT.md). The learner-facing screens: new pages under
 * /learn (routes/tutor/student.php) and the real views behind the wave-1 domains' learner routes, which keep their
 * controllers and behaviour. View composers add presentation data (the reader's step, explained match rows, open
 * times); they never change what a domain decides.
 */
class TutorStudentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LearnerContext::class);
    }

    public function boot(): void
    {
        View::composer('student.find.index', FindComposer::class);
        View::composer('student.storefront', StorefrontComposer::class);
        View::composer('student.lessons', LessonsComposer::class);
        View::composer(['student.*'], LearnerComposer::class);
    }
}
