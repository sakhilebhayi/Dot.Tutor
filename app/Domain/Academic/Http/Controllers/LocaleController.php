<?php

namespace App\Domain\Academic\Http\Controllers;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\I18n\LocaleCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The interface-language switcher. Guests' choice lives in the session; a
 * signed-in user's is saved as their `interface` language. Each option says
 * how much of the interface is translated and whether a native speaker
 * reviewed it.
 */
class LocaleController extends Controller
{
    public function index(LocaleCatalogue $catalogue): View
    {
        return view('academic::locale', ['options' => $catalogue->options(), 'current' => app()->getLocale()]);
    }

    public function update(Request $request, LocaleCatalogue $catalogue, LanguageProfile $languages): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in($catalogue->codes())]]);

        $request->session()->put('locale', $data['locale']);
        if ($request->user()) {
            $languages->sync($request->user(), LanguageRole::Interface, [$data['locale']]);
        }
        app()->setLocale($data['locale']);

        $name = collect($catalogue->options())->firstWhere('code', $data['locale'])['name_native'] ?? $data['locale'];

        return back(fallback: route('academic.locale.index'))->with('status', __('academic.locale.changed', ['language' => $name]));
    }
}
