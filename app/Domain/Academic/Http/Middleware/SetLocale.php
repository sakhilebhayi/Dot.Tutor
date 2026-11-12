<?php

namespace App\Domain\Academic\Http\Middleware;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\I18n\LocaleCatalogue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Interface locale, in order: the signed-in user's saved interface language,
 * the guest's switcher choice (session), the browser's Accept-Language (only
 * locales with translated strings), then English. The interface locale never
 * feeds matching.
 */
class SetLocale
{
    public function __construct(private readonly LocaleCatalogue $catalogue, private readonly LanguageProfile $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $fallback = (string) config('app.fallback_locale', 'en');

        try {
            $user = $request->user();
            $saved = $user ? $this->languages->interfaceLocale($user) : null;
            if ($this->catalogue->isSupported($saved)) {
                return $saved;
            }

            $chosen = $request->hasSession() ? $request->session()->get('locale') : null;
            if ($this->catalogue->isSupported($chosen)) {
                return $chosen;
            }

            $negotiated = $request->getPreferredLanguage($this->catalogue->translatedCodes());

            return $negotiated && $this->catalogue->isSupported($negotiated) ? $negotiated : $fallback;
        } catch (Throwable) {
            // A missing languages table (fresh install before migrate) must not take the site down.
            return $fallback;
        }
    }
}
