<?php

namespace App\Domain\Security;

use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\ContactRedactor;
use App\Domain\Security\Console\PrivacyPurge;
use App\Domain\Security\Http\Middleware\RequireRecentSignIn;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 security wiring (docs/R47_SECURITY.md): the recent-sign-in middleware alias and the sign-in timestamp it
 * reads. Aliases, limiters and listeners live here, never in route files (route:cache).
 */
class TutorSecurityServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PrivacyPurge::class]);
        }

        // The retention schedule (docs/R47_PRIVACY.md section 5); the host's cron runs schedule:run.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('tutor:privacy-purge')->dailyAt('03:50')->timezone('UTC')->withoutOverlapping()->onOneServer();
        });

        $router->aliasMiddleware('tutor.recent-auth', RequireRecentSignIn::class);

        // Personal-data export: a heavy read, a few times an hour.
        RateLimiter::for('tutor-data-export', fn (Request $request) => Limit::perHour(5)->by('data-export:'.$request->user()?->id));

        // Free text on a lesson (what the learner wants help with, where to meet) reaches the tutor before a guardian
        // has approved anything and a group lesson's note reaches every seat holder: contact details are hidden when
        // a learner under 18 (or of unknown age) is the student, and always on group lessons (R-47 security S-14).
        TutorSession::saving(function (TutorSession $session): void {
            if (! $session->isDirty(['notes', 'location_note'])) {
                return;
            }

            $student = $session->student_id ? User::query()->find($session->student_id) : null;
            $protect = $session->kind === TutorSession::KIND_GROUP
                || ($student !== null && app(AccountStanding::class)->isMinor($student));

            if (! $protect) {
                return;
            }

            $redactor = app(ContactRedactor::class);

            foreach (['notes', 'location_note'] as $field) {
                if (filled($session->{$field}) && $session->isDirty($field)) {
                    $session->{$field} = $redactor->redact((string) $session->{$field})['text'];
                }
            }
        });

        Event::listen(Login::class, function (Login $event): void {
            $request = request();

            if ($request->hasSession()) {
                $request->session()->put(RequireRecentSignIn::SIGNED_IN_AT, time());
            }
        });
    }
}
