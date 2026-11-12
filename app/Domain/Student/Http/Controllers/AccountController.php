<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\I18n\LocaleCatalogue;
use App\Domain\Academic\Models\Language;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Student\Models\StudentPreference;
use App\Domain\Student\Services\LearnerContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The learner's account page (D-1114): languages by role, the interface language with its real translation coverage,
 * the reading step, guardian link status for minors, and the links to purchases, notifications and the InfoDot
 * account. Languages and the interface locale are saved by the academic domain's own routes.
 */
class AccountController extends Controller
{
    public function show(Request $request, LanguageProfile $languages, LocaleCatalogue $catalogue, AccountStanding $standing,
        Guardians $guardians, LearnerContext $context): View
    {
        $user = $request->user();
        $names = Language::query()->get(['code', 'name_en', 'name_native'])->keyBy('code');

        return view('student.account', [
            'ctx' => $context->for($user),
            'names' => $names,
            'roles' => [
                'Taught in' => $languages->codes($user, LanguageRole::PrefersTeaching),
                'Also understands' => $languages->codes($user, LanguageRole::Understands),
                'Learning' => $languages->codes($user, LanguageRole::Learning),
            ],
            'localeOptions' => $catalogue->options(),
            'currentLocale' => app()->getLocale(),
            'standing' => $standing->summary($user),
            'guardians' => $guardians->guardiansOf($user),
            'invitations' => class_exists(GuardianInvitation::class)
                ? GuardianInvitation::query()->where('learner_id', $user->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')->get()
                : collect(),
        ]);
    }

    public function step(Request $request, LearnerContext $context): RedirectResponse
    {
        $step = $request->validate(['step' => ['required', Rule::in(StudentPreference::STEPS)]])['step'];
        StudentPreference::query()->updateOrCreate(['user_id' => $request->user()->id], ['step' => $step]);
        $context->forget($request->user());

        return redirect()->route('learn.account')->with('status', match ($step) {
            'young' => 'The larger, simpler view is on.',
            'standard' => 'The standard view is on.',
            default => 'The view now follows your grade.',
        });
    }
}
