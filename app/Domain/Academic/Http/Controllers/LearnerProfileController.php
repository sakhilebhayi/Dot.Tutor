<?php

namespace App\Domain\Academic\Http\Controllers;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Http\Requests\SaveLearnerProfileRequest;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Institution;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Academic\Services\NeedParser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one-minute learner profile: describe-what-you-need (optional), then
 * country, level (grouped by curriculum), subjects at that level, month and
 * year of birth, teaching languages. Works without JavaScript: choosing a
 * level reloads the page with that level's subjects.
 */
class LearnerProfileController extends Controller
{
    public function edit(Request $request, AcademicScope $scope, LanguageProfile $languages, NeedParser $parser): View
    {
        $user = $request->user();
        $profile = LearnerProfile::query()->with('subjects')->where('user_id', $user->id)->first();

        $countries = Country::query()->active()->orderBy('sort')->orderBy('name')->get();
        $countryId = $request->integer('country') ?: $profile?->country_id ?: $countries->first()?->id;

        $levelId = $request->integer('level') ?: $profile?->academic_level_id;
        $subjectIds = $profile?->subjects->pluck('id')->all() ?? [];
        $teaching = $languages->codes($user, LanguageRole::PrefersTeaching);

        $parsed = null;
        $need = trim((string) $request->query('need', ''));
        if ($need !== '') {
            $parsed = $parser->parse($need, $profile?->curriculum_id);
            $levelId = $parsed['levels'][0]['id'] ?? $levelId;
            $parsedSubjects = collect($parsed['offerings'])->where('level_id', $levelId)->pluck('subject_id')->all();
            $subjectIds = $parsedSubjects ?: $subjectIds;
            $stated = collect($parsed['teaching_languages'])->where('certainty', 'stated')->pluck('code')->all();
            $teaching = $stated ?: $teaching;
        }

        $curricula = Curriculum::query()->active()->availableIn($countryId)->orderBy('sort')
            ->with(['levels' => fn ($q) => $q->active()])->get();
        $level = $levelId ? AcademicLevel::query()->with(['stage', 'curriculum'])->find($levelId) : null;
        if ($level && ! $curricula->contains('id', $level->curriculum_id)) {
            $level = null;
        }

        return view('academic::learner.profile', [
            'profile' => $profile,
            'countries' => $countries,
            'countryId' => $countryId,
            'curricula' => $curricula,
            'level' => $level,
            'offerings' => $level ? $scope->offeringsAtLevel($level->id) : collect(),
            'subjectIds' => $subjectIds,
            'institutions' => $level?->isTertiary() ? Institution::query()->active()->where('country_id', $countryId)->orderBy('name')->get() : collect(),
            'languages' => Language::query()->active()->orderBy('sort')->get(),
            'teaching' => $teaching,
            'understands' => $languages->codes($user, LanguageRole::Understands),
            'learning' => $languages->codes($user, LanguageRole::Learning),
            'need' => $need,
            'parsed' => $parsed,
        ]);
    }

    public function update(SaveLearnerProfileRequest $request, LearnerProfiles $profiles): RedirectResponse
    {
        $profiles->save($request->user(), $request->validated());

        return redirect()->route('academic.profile.edit')->with('status', __('academic.onboarding.saved'));
    }
}
