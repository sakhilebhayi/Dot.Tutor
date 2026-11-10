<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Console\AuditVerify;
use App\Domain\Safety\Console\EscalateIncidents;
use App\Domain\Safety\Console\ExpireApprovals;
use App\Domain\Safety\Contracts\AuditLog;
use App\Domain\Safety\Contracts\BirthDateSource;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Contracts\RoomDirectory;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Listeners\GuardLegacyBookings;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Policies\ConversationPolicy;
use App\Domain\Safety\Policies\MessagePolicy;
use App\Domain\Safety\Services\DatabaseAuditLog;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Domain\Safety\Services\DatabaseReportSink;
use App\Domain\Safety\Services\SafeguardingRules;
use App\Domain\Safety\Support\NullBirthDateSource;
use App\Domain\Safety\Support\NullRoomDirectory;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 safety domain. Binds the real safeguarding seams (gate, guardian approvals, report sink, audit log) and
 * the honest null defaults for what other domains supply (dates of birth from the academic learner profile, rooms
 * from community). Everything is registered here, never in route files (route:cache).
 */
class TutorSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Seams this domain owns: the real implementations. Plain binds replace any bindIf placeholder another
        // domain's provider registered earlier.
        $this->app->singleton(AuditLog::class, DatabaseAuditLog::class);
        $this->app->singleton(SafeguardingGate::class, SafeguardingRules::class);
        $this->app->singleton(GuardianApprovals::class, DatabaseGuardianApprovals::class);
        $this->app->singleton(ReportSink::class, DatabaseReportSink::class);

        // Seams other domains supply; null until the integration provider binds them.
        $this->app->bindIf(BirthDateSource::class, NullBirthDateSource::class, true);
        $this->app->bindIf(RoomDirectory::class, NullRoomDirectory::class, true);
    }

    public function boot(): void
    {
        if (config('filesystems.disks.safety') === null) {
            config(['filesystems.disks.safety' => [
                'driver' => 'local',
                'root' => storage_path('app/private/safety'),
                'serve' => false,
                'throw' => false,
                'report' => false,
            ]]);
        }

        $this->loadViewsFrom(__DIR__.'/resources/views', 'safety');

        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);

        // Safeguarding staff. The ops agent may redefine this with operator roles (safeguarding_officer).
        Gate::define('safety.operate', fn (User $user) => (bool) $user->is_platform_operator);

        // Every gate ability as a Laravel ability, so policies elsewhere can write Gate::authorize('safety.book', $tutor).
        foreach (SafeguardingGate::ABILITIES as $ability) {
            Gate::define('safety.'.$ability, function (User $user, mixed $subject = null, array $context = []) use ($ability) {
                $decision = app(SafeguardingGate::class)->allows($user, $ability, $subject, $context);

                return $decision->allowed ? Response::allow() : Response::deny($decision->message(), $decision->codes()[0] ?? null);
            });
        }

        RateLimiter::for('safety-invitations', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('safety-reports', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('safety-messages', fn (Request $request) => Limit::perMinute((int) config('safety.messaging.per_minute') + 5)
            ->by($request->user()?->id ?: $request->ip()));

        TutorSession::observe(GuardLegacyBookings::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireApprovals::class, EscalateIncidents::class, AuditVerify::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('safety:escalate-incidents')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
            $schedule->command('safety:expire-approvals')->hourly()->withoutOverlapping()->onOneServer();
            $schedule->command('safety:audit-verify')->dailyAt('04:00')->timezone('UTC')->onOneServer();
        });
    }
}
