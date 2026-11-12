<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Http\Controllers\LearnerProfileController;
use App\Domain\Academic\Http\Requests\SaveLearnerProfileRequest;
use App\Domain\Academic\I18n\LocaleCatalogue;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Student\Services\LearnerContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * First run (D-1106): the academic learner profile plus the interface language, then the guardian step for minors.
 * The profile is validated and saved by the academic domain (SaveLearnerProfileRequest, LearnerProfiles); the
 * interface language by the academic LanguageProfile, exactly as the /language switcher does.
 */
class OnboardingController extends Controller
{
    public function show(Request $request, LocaleCatalogue $catalogue, LearnerContext $context, Guardians $guardians): View
    {
        if ($request->query('step') === 'guardian') {
            $user = $request->user();

            return view('student.onboarding-guardian', [
                'ctx' => $context->for($user),
                'guardians' => $guardians->guardiansOf($user),
            ]);
        }

        /** @var View $academic */
        $academic = app()->call([app(LearnerProfileController::class), 'edit']);

        return view('student.onboarding', $academic->getData() + [
            'localeOptions' => $catalogue->options(),
            'currentLocale' => app()->getLocale(),
        ]);
    }

    public function store(SaveLearnerProfileRequest $request, LearnerProfiles $profiles, LanguageProfile $languages,
        LocaleCatalogue $catalogue, LearnerContext $context): RedirectResponse
    {
        $locale = $request->validate(['interface_locale' => ['nullable', 'string', Rule::in($catalogue->codes())]])['interface_locale'] ?? null;
        $user = $request->user();

        $profiles->save($user, $request->validated());
        if ($locale !== null) {
            $request->session()->put('locale', $locale);
            $languages->sync($user, LanguageRole::Interface, [$locale]);
        }

        $context->forget($user);
        $ctx = $context->for($user);

        if ($ctx['minor'] && $ctx['limited']) {
            return redirect()->route('learn.onboarding', ['step' => 'guardian'])->with('status', __('academic.onboarding.saved'));
        }

        return redirect()->route('learn.home')->with('flash.banner', __('academic.onboarding.saved'));
    }
}
