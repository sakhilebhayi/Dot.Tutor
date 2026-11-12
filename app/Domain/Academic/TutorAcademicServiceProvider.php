<?php

namespace App\Domain\Academic;

use App\Domain\Academic\Console\SyncAcademicReferenceData;
use App\Domain\Academic\Contracts\AcademicAudit;
use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Contracts\AgeBandResolver;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Http\Middleware\SetLocale;
use App\Domain\Academic\I18n\LocaleCatalogue;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\Topic;
use App\Domain\Academic\Services\DatabaseAcademicScope;
use App\Domain\Academic\Services\DatabaseLanguageProfile;
use App\Domain\Academic\Support\LogAcademicAudit;
use App\Domain\Academic\Support\UnknownAgeBand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 academic domain: academic structure, languages, learner profiles,
 * interface locale and translation provenance. Seams are bound with bindIf so
 * the wave-2 integration provider can rebind them.
 */
class TutorAcademicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(AcademicScope::class, DatabaseAcademicScope::class);
        $this->app->bindIf(LanguageProfile::class, DatabaseLanguageProfile::class);
        $this->app->bindIf(AgeBandResolver::class, UnknownAgeBand::class);
        $this->app->bindIf(AcademicAudit::class, LogAcademicAudit::class);
        $this->app->singletonIf(Translations::class);
        $this->app->scopedIf(LocaleCatalogue::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(app_path('Domain/Academic/resources/views'), 'academic');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncAcademicReferenceData::class]);
        }

        // Interface locale for every web request (after the session starts, so the switcher choice is readable).
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', SetLocale::class);

        RateLimiter::for('academic-describe', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        $translations = $this->app->make(Translations::class);
        $translations->register('academic.subject', Subject::class, ['name', 'description']);
        $translations->register('academic.level', AcademicLevel::class, ['name']);
        $translations->register('academic.topic', Topic::class, ['name', 'description']);
    }
}
