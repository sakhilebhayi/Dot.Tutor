<?php

namespace App\Domain\Learning\Providers;

use App\Domain\Learning\Contracts\ProgressFeed;
use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Domain\Learning\Contracts\Seams\CheckoutGateway;
use App\Domain\Learning\Contracts\Seams\ContentReportSink;
use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Policies\AssessmentPolicy;
use App\Domain\Learning\Policies\CoursePolicy;
use App\Domain\Learning\Policies\LibraryResourcePolicy;
use App\Domain\Learning\Seams\DatabaseTutorStanding;
use App\Domain\Learning\Seams\LogAuditTrail;
use App\Domain\Learning\Seams\NullAcademicScopeLookup;
use App\Domain\Learning\Seams\NullCheckoutGateway;
use App\Domain\Learning\Seams\NullContentReportSink;
use App\Domain\Learning\Seams\NullEntitlementLookup;
use App\Domain\Learning\Seams\NullSafeguardingCheck;
use App\Domain\Learning\Services\DatabaseProgressFeed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 learning domain. Seams to other domains are bound with bindIf to honest defaults (D-601); the wave-2
 * integration provider, registered after this one, binds adapters to the owners' real implementations.
 */
class TutorLearningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(AcademicScopeLookup::class, NullAcademicScopeLookup::class);
        $this->app->bindIf(EntitlementLookup::class, NullEntitlementLookup::class);
        $this->app->bindIf(CheckoutGateway::class, NullCheckoutGateway::class);
        $this->app->bindIf(ContentReportSink::class, NullContentReportSink::class);
        $this->app->bindIf(SafeguardingCheck::class, NullSafeguardingCheck::class);
        $this->app->bindIf(AuditTrail::class, LogAuditTrail::class);
        $this->app->bindIf(TutorStanding::class, DatabaseTutorStanding::class);
        $this->app->bindIf(ProgressFeed::class, DatabaseProgressFeed::class);

        // The private disk is defined here so config/filesystems.php (a shared file) stays untouched.
        $this->app['config']->set('filesystems.disks.learning', [
            'driver' => 'local',
            'root' => config('learning.disk_root', storage_path('app/private/learning')),
            'serve' => false,
            'throw' => true,
            'report' => false,
            'visibility' => 'private',
        ]);
    }

    public function boot(): void
    {
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(LibraryResource::class, LibraryResourcePolicy::class);
        Gate::policy(Assessment::class, AssessmentPolicy::class);

        foreach (['uploads', 'attempts', 'reports'] as $name) {
            RateLimiter::for('learning-'.$name, fn (Request $request) => Limit::perMinute((int) config('learning.throttle.'.$name, 20))
                ->by($request->user()?->id ?: $request->ip()));
        }
    }
}
