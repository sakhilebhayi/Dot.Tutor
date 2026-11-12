<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Models\TutorProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Profile v2: the tutor's own professional profile. Verification fields are
 * never written here (VerificationService only).
 */
class TutorProfileService
{
    public function __construct(private PrivateFiles $files, private AcademicScopePort $academic) {}

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function update(TutorProfile $profile, array $data): TutorProfile
    {
        $map = [
            'headline' => 'headline',
            'introduction' => 'bio',
            'teaching_approach' => 'teaching_approach',
            'experience_summary' => 'experience_summary',
            'years_experience' => 'years_experience',
            'teaches_online' => 'teaches_online',
            'teaches_in_person' => 'teaches_in_person',
            'in_person_area' => 'in_person_area',
            'group_size_min' => 'group_size_min',
            'group_size_max' => 'group_size_max',
            'hourly_rate_minor' => 'hourly_rate_minor',
            'public_handle' => 'public_handle',
        ];

        $changes = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $changes[$column] = $data[$input];
            }
        }

        $online = $changes['teaches_online'] ?? $profile->teaches_online;
        $inPerson = $changes['teaches_in_person'] ?? $profile->teaches_in_person;
        if (! $online && ! $inPerson) {
            throw ValidationException::withMessages(['teaches_online' => 'Choose at least one way to teach: online or in person.']);
        }
        if ($inPerson && blank($changes['in_person_area'] ?? $profile->in_person_area)) {
            throw ValidationException::withMessages(['in_person_area' => 'Say which area you teach in person (a suburb or town), so learners nearby can find you.']);
        }
        $min = $changes['group_size_min'] ?? $profile->group_size_min;
        $max = $changes['group_size_max'] ?? $profile->group_size_max;
        if ($min !== null && $max !== null && $min > $max) {
            throw ValidationException::withMessages(['group_size_min' => 'The smallest group cannot be larger than the largest.']);
        }

        $profile->fill($changes)->save();

        return $profile->refresh();
    }

    public function setPhoto(TutorProfile $profile, UploadedFile $photo): TutorProfile
    {
        $stored = $this->files->storeImage($photo, 'marketplace/photos/'.$profile->id, (int) config('marketplace.photo_max_px'), 'photo');
        $old = $profile->photo_path;

        $profile->forceFill(['photo_path' => $stored['path'], 'photo_updated_at' => now()])->save();
        $this->files->delete($old);

        return $profile;
    }

    public function removePhoto(TutorProfile $profile): void
    {
        $this->files->delete($profile->photo_path);
        $profile->forceFill(['photo_path' => null, 'photo_updated_at' => now()])->save();
    }

    /**
     * Replace the tutor's teaching scope. The legacy tutor_subject list is kept
     * equal to the scope's subjects so the existing booking form keeps working.
     *
     * @param  list<array{subject_id: int, curriculum_id?: ?int, level_min_id?: ?int, level_max_id?: ?int, notes?: ?string}>  $scopes
     */
    public function syncTeachingScopes(TutorProfile $profile, array $scopes): void
    {
        foreach ($scopes as $i => $scope) {
            $error = $this->academic->validateTeachingScope(
                (int) $scope['subject_id'],
                $scope['curriculum_id'] ?? null,
                $scope['level_min_id'] ?? null,
                $scope['level_max_id'] ?? null,
            );
            if ($error !== null) {
                throw ValidationException::withMessages(["scopes.{$i}" => $error]);
            }
        }

        DB::transaction(function () use ($profile, $scopes) {
            $profile->teachingScopes()->delete();
            foreach ($scopes as $scope) {
                TutorTeachingScope::create([
                    'tutor_profile_id' => $profile->id,
                    'subject_id' => (int) $scope['subject_id'],
                    'curriculum_id' => $scope['curriculum_id'] ?? null,
                    'level_min_id' => $scope['level_min_id'] ?? null,
                    'level_max_id' => $scope['level_max_id'] ?? null,
                    'notes' => $scope['notes'] ?? null,
                ]);
            }
            $profile->subjects()->sync(array_values(array_unique(array_map(fn ($s) => (int) $s['subject_id'], $scopes))));
        });
    }
}
