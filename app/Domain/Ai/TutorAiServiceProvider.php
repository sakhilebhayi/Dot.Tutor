<?php

namespace App\Domain\Ai;

use App\Domain\Academic\Translations;
use App\Domain\Ai\Console\PurgeAiInteractions;
use App\Domain\Ai\Contracts\AiTutor;
use App\Domain\Ai\Drivers\AnthropicAiTutor;
use App\Domain\Ai\Drivers\NullAiTutor;
use App\Domain\Ai\Support\TranslatableResource;
use App\Domain\Learning\Models\Course;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 AI assistant (owner: ai agent, D-900 onward). The AiTutor binding is the Anthropic driver only when AI is
 * switched on and a key is present; otherwise the null driver (nothing is ever sent). bindIf, so the integration
 * provider or tests can rebind it.
 */
class TutorAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(AiTutor::class, function ($app) {
            return config('ai.enabled') && filled(config('ai.anthropic.api_key'))
                ? $app->make(AnthropicAiTutor::class)
                : new NullAiTutor;
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(resource_path('views/ai'), 'ai');

        RateLimiter::for('ai-requests', fn (Request $request) => Limit::perMinute((int) config('ai.limits.per_minute', 6))
            ->by('ai:'.($request->user()?->id ?: $request->ip())));

        Gate::define('ai.review-log', fn (User $user) => (bool) $user->is_platform_operator);

        // Content the assistant may machine-translate. Registered only when the owning domain has not done it, under
        // the owner's namespace so stored translations survive when the owner registers the type itself (D-912).
        $translations = $this->app->make(Translations::class);
        $types = $translations->types();
        $defaults = [
            'learning.course' => [Course::class, ['title', 'description']],
            'learning.resource' => [TranslatableResource::class, ['title', 'description']],
            'marketplace.tutor_profile' => [TutorProfile::class, ['headline', 'bio', 'teaching_approach']],
        ];
        foreach ($defaults as $alias => [$class, $fields]) {
            if (! isset($types[$alias])) {
                $translations->register($alias, $class, $fields);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeAiInteractions::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('ai:purge-interactions')->dailyAt('03:30')->timezone('UTC')->onOneServer();
        });
    }
}
