<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Enums\TeachingProficiency;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Models\UserLanguage;
use App\Domain\Academic\Support\LearnerLanguagePreferences;
use App\Domain\Academic\Support\TeachingLanguage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseLanguageProfile implements LanguageProfile
{
    public function codes(User|int $user, LanguageRole $role): array
    {
        return UserLanguage::query()
            ->join('languages', 'languages.id', '=', 'user_languages.language_id')
            ->where('user_languages.user_id', $this->id($user))
            ->where('user_languages.role', $role->value)
            ->orderBy('user_languages.id')
            ->pluck('languages.code')
            ->all();
    }

    public function interfaceLocale(User|int $user): ?string
    {
        return $this->codes($user, LanguageRole::Interface)[0] ?? null;
    }

    public function teachingLanguages(User|int $user): array
    {
        return $this->teachingLanguagesFor([$this->id($user)])[$this->id($user)] ?? [];
    }

    public function teachingLanguagesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = UserLanguage::query()
            ->with('language')
            ->whereIn('user_id', $userIds)
            ->where('role', LanguageRole::CanTeachIn->value)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->user_id][] = new TeachingLanguage(
                code: $row->language->code,
                nameEn: $row->language->name_en,
                proficiency: $row->proficiency ?? TeachingProficiency::Working,
                verified: $row->isVerified(),
            );
        }

        return $out;
    }

    public function learnerPreferences(User|int $user): LearnerLanguagePreferences
    {
        $bilingualOk = LearnerProfile::query()->where('user_id', $this->id($user))->value('bilingual_ok');

        return new LearnerLanguagePreferences(
            teaching: $this->codes($user, LanguageRole::PrefersTeaching),
            understands: $this->codes($user, LanguageRole::Understands),
            learning: $this->codes($user, LanguageRole::Learning),
            bilingualOk: $bilingualOk === null ? null : (bool) $bilingualOk,
        );
    }

    public function sync(User $user, LanguageRole $role, array $codes): void
    {
        if ($role === LanguageRole::CanTeachIn) {
            throw new InvalidArgumentException('Use syncTeachingLanguages() for can_teach_in.');
        }

        $codes = array_values(array_unique($codes));
        if ($role === LanguageRole::Interface) {
            $codes = array_slice($codes, 0, 1);
        }
        $ids = $this->languageIds($codes, interface: $role === LanguageRole::Interface);

        DB::transaction(function () use ($user, $role, $ids): void {
            UserLanguage::query()->where('user_id', $user->id)->where('role', $role->value)->delete();
            foreach ($ids as $languageId) {
                UserLanguage::query()->create(['user_id' => $user->id, 'language_id' => $languageId, 'role' => $role->value]);
            }
        });
    }

    public function syncTeachingLanguages(User $user, array $entries): void
    {
        $byCode = [];
        foreach ($entries as $entry) {
            $byCode[$entry['code']] = TeachingProficiency::from($entry['proficiency']);
        }
        $ids = $this->languageIds(array_keys($byCode));

        DB::transaction(function () use ($user, $byCode, $ids): void {
            $existing = UserLanguage::query()->where('user_id', $user->id)->where('role', LanguageRole::CanTeachIn->value)->get()->keyBy('language_id');

            foreach ($existing as $languageId => $row) {
                if (! in_array($languageId, $ids, true)) {
                    $row->delete();
                }
            }

            foreach ($ids as $code => $languageId) {
                $proficiency = $byCode[$code];
                $row = $existing[$languageId] ?? new UserLanguage(['user_id' => $user->id, 'language_id' => $languageId, 'role' => LanguageRole::CanTeachIn->value]);
                if ($row->exists && $row->proficiency !== $proficiency) {
                    // A changed claim is a new claim: the old verification does not cover it.
                    $row->forceFill(['verified_at' => null, 'verified_by_id' => null]);
                }
                $row->proficiency = $proficiency;
                $row->save();
            }
        });
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, int> code => id, in the given order
     */
    private function languageIds(array $codes, bool $interface = false): array
    {
        $languages = Language::query()->active()->whereIn('code', $codes)
            ->when($interface, fn ($q) => $q->where('modality', 'written'))
            ->pluck('id', 'code');

        $ids = [];
        foreach ($codes as $code) {
            if (! isset($languages[$code])) {
                throw new InvalidArgumentException("Unknown or inactive language [{$code}].");
            }
            $ids[$code] = (int) $languages[$code];
        }

        return $ids;
    }

    private function id(User|int $user): int
    {
        return $user instanceof User ? $user->id : $user;
    }
}
