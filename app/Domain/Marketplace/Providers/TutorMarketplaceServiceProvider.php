<?php

namespace App\Domain\Marketplace\Providers;

use App\Domain\Marketplace\Console\ExpireMarketplaceHolds;
use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\AuditPort;
use App\Domain\Marketplace\Contracts\CheckoutPort;
use App\Domain\Marketplace\Contracts\GuardianApprovalsPort;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Contracts\LearnerAgePort;
use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Contracts\TutorDirectory;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Seams\NullAcademicScope;
use App\Domain\Marketplace\Seams\NullAudit;
use App\Domain\Marketplace\Seams\NullCheckout;
use App\Domain\Marketplace\Seams\NullGuardianApprovals;
use App\Domain\Marketplace\Seams\NullLanguageProfile;
use App\Domain\Marketplace\Seams\NullLearnerAge;
use App\Domain\Marketplace\Seams\NullReportSink;
use App\Domain\Marketplace\Seams\NullSafeguarding;
use App\Domain\Marketplace\Services\EloquentTutorDirectory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 marketplace domain. Binds every seam with an honest null default
 * (bindIf, so the wave-2 integration provider registered later wins), the
 * rate limiters, the scheduled hold expiry and the {error, message} JSON
 * rendering for /api/tutor/*.
 */
class TutorMarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(AcademicScopePort::class, NullAcademicScope::class);
        $this->app->bindIf(LanguageProfilePort::class, NullLanguageProfile::class);
        $this->app->bindIf(LearnerAgePort::class, NullLearnerAge::class);
        $this->app->bindIf(GuardianApprovalsPort::class, NullGuardianApprovals::class);
        $this->app->bindIf(CheckoutPort::class, NullCheckout::class);
        $this->app->bindIf(ReportSinkPort::class, NullReportSink::class);
        $this->app->bindIf(AuditPort::class, NullAudit::class);
        $this->app->bindIf(SafeguardingPort::class, NullSafeguarding::class);
        $this->app->bindIf(TutorDirectory::class, EloquentTutorDirectory::class);
    }

    public function boot(): void
    {
        RateLimiter::for('marketplace-matching', fn (Request $request) => Limit::perMinute((int) config('marketplace.throttle.matching'))
            ->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('marketplace-reviews', fn (Request $request) => Limit::perMinute((int) config('marketplace.throttle.reviews'))
            ->by($request->user()?->id ?: $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireMarketplaceHolds::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('tutor:expire-marketplace-holds')->everyFiveMinutes()->withoutOverlapping();
        });

        $this->registerJsonErrors();
    }

    /** MarketplaceException answers {error, message} under /api/tutor/* and a form error elsewhere. */
    private function registerJsonErrors(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $isApi = fn (Request $request) => $request->is('api/tutor', 'api/tutor/*') || $request->expectsJson();

        $handler->renderable(function (MarketplaceException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['error' => $e->error, 'message' => $e->getMessage()], $e->status);
            }

            return back()->withErrors(['marketplace' => $e->getMessage()])->withInput();
        });
        // Framework exceptions (validation, authentication, authorisation, not found, throttling, HTTP errors)
        // are rendered as {error, message} app-wide by bootstrap/app.php (R-47 D-1404).
    }
}
