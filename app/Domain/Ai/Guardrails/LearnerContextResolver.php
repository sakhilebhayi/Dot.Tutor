<?php

namespace App\Domain\Ai\Guardrails;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\Subject;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\AgeResolver;
use App\Models\User;

/**
 * Builds the assistant's view of a person from the safety domain (age band) and the academic domain (curriculum,
 * level, subjects, languages). Age: a known date of birth decides the band; an active guardian link means a minor
 * (teen); otherwise config ai.unknown_age_band applies (child by default: the most protected behaviour, D-905).
 */
class LearnerContextResolver
{
    public function __construct(
        private readonly AgeResolver $ages,
        private readonly AcademicScope $academic,
        private readonly LanguageProfile $languages,
    ) {}

    public function for(User $user, ?string $language = null): LearnerContext
    {
        [$band, $known] = $this->band($user);

        $scope = $this->academic->forLearner($user);
        $level = $scope?->levelId ? AcademicLevel::query()->with(['curriculum', 'stage'])->find($scope->levelId) : null;
        $subjects = $scope && $scope->subjectIds !== []
            ? Subject::query()->whereIn('id', $scope->subjectIds)->orderBy('name')->limit(12)->pluck('name')->all()
            : [];

        $code = $this->answerLanguage($user, $language);

        return new LearnerContext(
            userId: $user->id,
            band: $band,
            ageKnown: $known,
            curriculum: $level?->curriculum?->label(),
            level: $level?->name,
            stage: $level?->stage?->code,
            subjects: $subjects,
            language: $code,
            languageName: self::languageName($code),
        );
    }

    /** @return array{0: string, 1: bool} band and whether the age is known */
    public function band(User $user): array
    {
        $known = $this->ages->knownBand($user);
        if ($known !== null) {
            return [$known->value, true];
        }
        if (GuardianLink::query()->active()->where('learner_id', $user->id)->exists()) {
            return ['teen', false];
        }
        $fallback = (string) config('ai.unknown_age_band', 'child');

        return [in_array($fallback, ['child', 'teen', 'adult'], true) ? $fallback : 'child', false];
    }

    /** The requested language when it is an active written language; else the learner's preferred teaching language. */
    public function answerLanguage(User $user, ?string $requested): string
    {
        if ($requested !== null && self::isAnswerLanguage($requested)) {
            return $requested;
        }
        foreach ($this->languages->learnerPreferences($user)->teaching as $code) {
            if (self::isAnswerLanguage($code)) {
                return $code;
            }
        }
        $interface = $this->languages->interfaceLocale($user);

        return $interface !== null && self::isAnswerLanguage($interface) ? $interface : 'en';
    }

    public static function isAnswerLanguage(string $code): bool
    {
        return $code === 'en' || Language::query()->active()->where('code', $code)->where('modality', 'written')->exists();
    }

    public static function languageName(string $code): string
    {
        $language = Language::query()->where('code', $code)->first();

        return $language?->name_en ?? ($code === 'en' ? 'English' : $code);
    }

    /** @return list<array{code: string, name: string}> */
    public static function answerLanguages(): array
    {
        $rows = Language::query()->active()->where('modality', 'written')->orderBy('sort')->orderBy('name_en')->get(['code', 'name_en', 'name_native'])
            ->map(fn (Language $l) => ['code' => $l->code, 'name' => $l->displayName()])->all();

        return $rows !== [] ? array_values($rows) : [['code' => 'en', 'name' => 'English']];
    }
}
