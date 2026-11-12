<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\TeachingProficiency;
use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Enums\QualificationKind;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Models\QualificationEvidence;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Services\QualificationService;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Marketplace\Services\TutorProfileService;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Services\Screening;
use App\Domain\Teach\Models\Onboarding;
use App\Domain\Teach\Services\AcademicOptions;
use App\Domain\Teach\Services\OnboardingProgress;
use App\Domain\Teach\Support\TutorContext;
use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Becoming a tutor (brief sections 7, 16 and the tutor journey in 25): a guided onboarding that starts as a draft
 * and grows from peer student tutor to professional. Each step saves through the marketplace, academic and safety
 * services; nothing here decides verification, which only an operator's recorded review can do.
 */
class OnboardingController extends Controller
{
    public function __construct(private OnboardingProgress $progress) {}

    public function show(Request $request, AcademicOptions $options, LanguageProfile $languages, TutorPresenter $presenter, Screening $screening): View
    {
        $profile = $request->user()->tutorProfile()->first();
        $steps = $this->progress->steps($profile);
        $step = in_array($request->query('step'), OnboardingProgress::STEPS, true) ? $request->query('step') : null;
        if ($step === null) {
            $step = $profile === null ? 'about' : (collect($steps)->first(fn ($s) => ! $s['done'] && $s['required'])['key'] ?? 'send');
        }
        if ($profile === null) {
            $step = 'about';
        }

        $onboarding = $profile ? Onboarding::query()->where('tutor_profile_id', $profile->id)->first() : null;
        $levelIndex = $options->levelIndex();

        return view('teach.profile', [
            'profile' => $profile,
            'step' => $step,
            'steps' => $steps,
            'state' => $this->progress->state($profile),
            'missing' => $this->progress->missing($profile),
            'onboarding' => $onboarding,
            'subjects' => $options->subjects(),
            'levels' => $options->levels(),
            'levelIndex' => $levelIndex,
            'allLanguages' => $options->languages(),
            'teaching' => $profile ? $languages->teachingLanguages($profile->user_id) : [],
            'proficiencies' => TeachingProficiency::cases(),
            'scopes' => $profile?->teachingScopes()->with('subject')->get() ?? collect(),
            'qualifications' => $profile?->qualificationRecords()->with('evidence')->get() ?? collect(),
            'kinds' => QualificationKind::cases(),
            'levelsLadder' => VerificationLevel::cases(),
            'verification' => $profile ? $presenter->verification($profile) : null,
            'screeningMissing' => $profile && $profile->user ? $screening->missingKinds($profile->user) : [],
            'windows' => $profile?->availabilityWindows()->get() ?? collect(),
            'offerings' => $profile?->offerings()->where('is_active', true)->get() ?? collect(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('tutor.timezone'),
            'currency' => $profile?->currency ?: config('tutor.currency'),
        ]);
    }

    /** The first save creates the profile as a draft: no operator sees it until the tutor sends it. */
    public function start(Request $request, TutorProfileService $service): RedirectResponse
    {
        if ($request->user()->tutorProfile()->exists()) {
            return $this->about($request, $service);
        }

        $data = $this->validateAbout($request);

        try {
            DB::transaction(function () use ($request, $data, $service) {
                // Offerings own prices (D-2204): a new profile has no profile-wide rate. The legacy decimal column
                // is NOT NULL, so it holds 0 while the minor-unit rate stays null ("not set"), written without the
                // model's rate-sync hook, which would turn the 0 into a R0.00 rate.
                $profile = TutorProfile::create([
                    'user_id' => $request->user()->id,
                    'headline' => $data['headline'],
                    'bio' => $data['introduction'],
                    'hourly_rate' => 0,
                    'currency' => config('tutor.currency'),
                    'timezone' => $data['timezone'],
                    'status' => TutorProfileStatus::Draft->value,
                ]);
                DB::table('tutor_profiles')->where('id', $profile->id)->update(['hourly_rate_minor' => null]);
                $profile->hourly_rate_minor = null;
                $profile->syncOriginalAttribute('hourly_rate_minor');
                $service->update($profile, $this->profileFields($data));
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('teach.profile');
        }

        return redirect()->route('teach.profile', ['step' => 'photo'])
            ->with('success', 'Draft saved. Nobody sees it until you send it for review.');
    }

    public function about(Request $request, TutorProfileService $service): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $data = $this->validateAbout($request);

        $profile->forceFill(['timezone' => $data['timezone']])->save();
        $service->update($profile, [
            ...$this->profileFields($data),
            'headline' => $data['headline'],
            'introduction' => $data['introduction'],
        ]);

        return $this->next($request, 'about', 'About you saved.');
    }

    public function photo(Request $request, TutorProfileService $service): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:'.(int) config('marketplace.photo_max_kb')]]);
        $service->setPhoto(TutorContext::profile($request), $request->file('photo'));

        return $this->next($request, 'photo', 'Photo saved. Location and camera details were removed from it.');
    }

    public function removePhoto(Request $request, TutorProfileService $service): RedirectResponse
    {
        $service->removePhoto(TutorContext::profile($request));

        return back()->with('success', 'Photo removed.');
    }

    public function scope(Request $request, TutorProfileService $service, AcademicScopePort $academic): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $data = $request->validate([
            'scopes' => ['required', 'array', 'max:30'],
            'scopes.*.subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')->where('is_active', true)],
            'scopes.*.level_min_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.level_max_id' => ['nullable', 'integer', 'min:1'],
            'scopes.*.notes' => ['nullable', 'string', 'max:300'],
        ]);

        $rows = collect($data['scopes'])->filter(fn ($row) => filled($row['subject_id'] ?? null))->values()->map(function ($row) use ($academic) {
            $min = isset($row['level_min_id']) ? (int) $row['level_min_id'] : null;
            $max = isset($row['level_max_id']) ? (int) $row['level_max_id'] : null;
            $anchor = $min ?? $max;

            return [
                'subject_id' => (int) $row['subject_id'],
                'curriculum_id' => $anchor ? $academic->levelCurriculum($anchor) : null,
                'level_min_id' => $min ?? $max,
                'level_max_id' => $max ?? $min,
                'notes' => $row['notes'] ?? null,
            ];
        })->all();

        if ($rows === []) {
            throw ValidationException::withMessages(['scopes' => 'Choose at least one subject you teach.']);
        }

        $service->syncTeachingScopes($profile, $rows);

        return $this->next($request, 'scope', 'What you teach is saved.');
    }

    public function languages(Request $request, LanguageProfile $languages): RedirectResponse
    {
        TutorContext::profile($request);
        $data = $request->validate([
            'languages' => ['required', 'array', 'max:12'],
            'languages.*.code' => ['nullable', 'string', Rule::exists('languages', 'code')->where('is_active', true)],
            'languages.*.proficiency' => ['nullable', Rule::enum(TeachingProficiency::class)],
        ]);

        $entries = collect($data['languages'])->filter(fn ($l) => filled($l['code'] ?? null))
            ->unique('code')->values()
            ->map(fn ($l) => ['code' => $l['code'], 'proficiency' => $l['proficiency'] ?? TeachingProficiency::Fluent->value])->all();

        if ($entries === []) {
            throw ValidationException::withMessages(['languages' => 'Choose at least one language you can teach in.']);
        }
        if (count($entries) > 8) {
            throw ValidationException::withMessages(['languages' => 'Choose at most eight teaching languages.']);
        }

        $languages->syncTeachingLanguages($request->user(), $entries);

        return $this->next($request, 'languages', 'Teaching languages saved. They stay self-declared until an operator checks them.');
    }

    public function storeQualification(Request $request, QualificationService $service): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(QualificationKind::values())],
            'title' => ['required', 'string', 'max:200'],
            'institution' => ['nullable', 'string', 'max:200'],
            'year_awarded' => ['nullable', 'integer', 'min:1950', 'max:'.((int) date('Y') + 1)],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:'.(int) config('marketplace.evidence_max_kb')],
        ]);

        DB::transaction(function () use ($service, $profile, $data, $request) {
            $q = $service->create($profile, collect($data)->except('file')->all());
            if ($request->hasFile('file')) {
                $service->addEvidence($q, $request->file('file'), $request->user());
            }
        });

        return back()->with('success', 'Qualification recorded. Attach evidence and send it for review when ready.');
    }

    public function destroyQualification(Request $request, TutorQualification $qualification, QualificationService $service): RedirectResponse
    {
        $service->delete($this->ownQualification($request, $qualification));

        return back()->with('success', 'Qualification removed.');
    }

    public function storeEvidence(Request $request, TutorQualification $qualification, QualificationService $service): RedirectResponse
    {
        $q = $this->ownQualification($request, $qualification);
        $request->validate(['file' => ['required', 'file', 'max:'.(int) config('marketplace.evidence_max_kb')]]);
        $service->addEvidence($q, $request->file('file'), $request->user());

        return back()->with('success', 'Evidence attached.');
    }

    public function destroyEvidence(Request $request, QualificationEvidence $evidence, QualificationService $service): RedirectResponse
    {
        $this->ownQualification($request, $evidence->qualification);
        $service->removeEvidence($evidence);

        return back()->with('success', 'Evidence removed.');
    }

    public function submitQualification(Request $request, TutorQualification $qualification, QualificationService $service): RedirectResponse
    {
        $service->submit($this->ownQualification($request, $qualification));

        return back()->with('success', 'Sent for review. An operator checks the evidence; the label changes only after that review.');
    }

    public function safeguarding(Request $request): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $data = $request->validate(['teaches_minors' => ['required', 'boolean']]);
        Onboarding::query()->updateOrCreate(['tutor_profile_id' => $profile->id], ['teaches_minors' => (bool) $data['teaches_minors']]);

        return $this->next($request, 'safeguarding', 'Saved.');
    }

    /** Send the profile for review: a draft, or a profile that was not approved or was suspended and has changed. */
    public function submit(Request $request): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $status = $profile->status;

        if (! in_array($status, [TutorProfileStatus::Draft->value, TutorProfileStatus::Rejected->value, TutorProfileStatus::Suspended->value], true)) {
            return back()->with('success', $status === TutorProfileStatus::Pending->value ? 'Your profile is already with an operator.' : 'Your profile is already approved.');
        }

        $missing = $this->progress->missing($profile);
        if ($missing !== []) {
            throw ValidationException::withMessages(['submit' => 'Finish these steps first: '.implode(', ', $missing).'.']);
        }

        $again = $status !== TutorProfileStatus::Draft->value;
        $data = $request->validate([
            'resubmission_note' => [$again ? 'required' : 'nullable', 'string', 'min:10', 'max:2000'],
        ], ['resubmission_note.required' => 'Say what you changed since the decision, so the reviewer can see it.']);

        DB::transaction(function () use ($profile, $again, $data, $request) {
            $profile->forceFill([
                'status' => TutorProfileStatus::Pending->value,
                ...($again ? ['rejected_reason' => null, 'suspended_reason' => null] : []),
            ])->save();
            Onboarding::query()->updateOrCreate(['tutor_profile_id' => $profile->id], [
                'submitted_at' => now(),
                'resubmission_note' => $again ? $data['resubmission_note'] : null,
            ]);
            Audit::record($request->user(), 'tutor.profile.submitted', $profile, ['again' => $again, 'note' => $again ? mb_substr($data['resubmission_note'], 0, 500) : null]);
        });

        return redirect()->route('teach.profile', ['step' => 'send'])
            ->with('success', 'Sent for review. You will see the decision here and in your notifications.');
    }

    /** @return array<string, mixed> */
    private function validateAbout(Request $request): array
    {
        $data = $request->validate([
            'headline' => ['required', 'string', 'max:160'],
            'introduction' => ['required', 'string', 'min:40', 'max:5000'],
            'teaching_approach' => ['nullable', 'string', 'max:3000'],
            'experience_summary' => ['nullable', 'string', 'max:3000'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:70'],
            'teaches_online' => ['nullable', 'boolean'],
            'teaches_in_person' => ['nullable', 'boolean'],
            'in_person_area' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'timezone:all'],
        ], [
            'introduction.min' => 'Write at least a few sentences (40 characters) so learners know who you are.',
        ]);

        $data['teaches_online'] = (bool) ($data['teaches_online'] ?? false);
        $data['teaches_in_person'] = (bool) ($data['teaches_in_person'] ?? false);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileFields(array $data): array
    {
        return collect($data)->only(['teaching_approach', 'experience_summary', 'years_experience', 'teaches_online', 'teaches_in_person', 'in_person_area'])->all();
    }

    private function ownQualification(Request $request, TutorQualification $qualification): TutorQualification
    {
        abort_unless($qualification->tutor_profile_id === TutorContext::profile($request)->id, 404);

        return $qualification;
    }

    private function next(Request $request, string $from, string $message): RedirectResponse
    {
        $i = array_search($from, OnboardingProgress::STEPS, true);
        $next = OnboardingProgress::STEPS[$i + 1] ?? 'send';

        return redirect()->route('teach.profile', ['step' => $request->boolean('stay') ? $from : $next])->with('success', $message);
    }
}
