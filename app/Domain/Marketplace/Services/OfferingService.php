<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Enums\TeachingFormat;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Models\TutorProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A tutor's offerings (brief section 7): what is sold, for how long, at what
 * price, in what format, for which subjects and levels, and the cancellation
 * terms. Offerings are archived, never deleted (bookings point at them), and a
 * price change never touches an existing booking (each booking snapshots it).
 */
class OfferingService
{
    public function __construct(private AcademicScopePort $academic) {}

    /**
     * Validation rules for create (all required) or update (partial).
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $min = (int) config('marketplace.min_duration_minutes');
        $max = (int) config('marketplace.max_duration_minutes');

        return [
            'type' => [$partial ? 'prohibited' : 'required', Rule::in(OfferingType::values())],
            'title' => [$req, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'price_minor' => [$req, 'integer', 'min:0', 'max:100000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'duration_minutes' => [$req, 'integer', "min:{$min}", "max:{$max}", 'multiple_of:15'],
            'format' => [$req, Rule::in(TeachingFormat::values())],
            'area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('marketplace.group_capacity_max')],
            'package_lessons' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:'.(int) config('marketplace.package_lessons_max')],
            'package_valid_days' => ['sometimes', 'nullable', 'integer', 'min:7', 'max:730'],
            'recurring_weeks' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:'.(int) config('marketplace.recurring_weeks_max')],
            'subject_ids' => [$req, 'array', 'min:1', 'max:10'],
            'subject_ids.*' => ['integer', 'distinct', 'exists:subjects,id'],
            'level_ids' => ['sometimes', 'nullable', 'array', 'max:30'],
            'level_ids.*' => ['integer', 'distinct'],
            'cancellation_notice_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'late_cancellation_refund_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $data  validated by rules() */
    public function create(TutorProfile $profile, array $data): TutorOffering
    {
        $data['currency'] ??= $profile->currency ?: config('tutor.currency');
        $type = OfferingType::from($data['type']);
        $data = $this->normalise($type, $data);
        $this->check($profile, $type, $data);

        return DB::transaction(function () use ($profile, $data) {
            $offering = TutorOffering::create([...collect($data)->except('subject_ids')->all(), 'tutor_profile_id' => $profile->id]);
            $offering->subjects()->sync($data['subject_ids']);

            return $offering->load('subjects');
        });
    }

    /** @param  array<string, mixed>  $data  validated by rules(partial: true) */
    public function update(TutorOffering $offering, array $data): TutorOffering
    {
        $type = $offering->typeEnum();
        $merged = $this->normalise($type, [
            ...$offering->only(['title', 'description', 'price_minor', 'currency', 'duration_minutes', 'format', 'area', 'capacity', 'package_lessons', 'package_valid_days', 'recurring_weeks', 'level_ids', 'cancellation_notice_hours', 'late_cancellation_refund_percent', 'is_active']),
            'subject_ids' => $offering->subjects()->pluck('subjects.id')->all(),
            ...$data,
        ]);
        $this->check($offering->tutorProfile, $type, $merged);

        return DB::transaction(function () use ($offering, $merged) {
            $offering->update(collect($merged)->except('subject_ids')->all());
            $offering->subjects()->sync($merged['subject_ids']);

            return $offering->load('subjects');
        });
    }

    public function archive(TutorOffering $offering): TutorOffering
    {
        $offering->update(['is_active' => false]);

        return $offering;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(OfferingType $type, array $data): array
    {
        if (! $type->isGroup()) {
            $data['capacity'] = 1;
        }
        if ($type !== OfferingType::Package) {
            $data['package_lessons'] = null;
            $data['package_valid_days'] = null;
        }
        if ($type !== OfferingType::RecurringWeekly) {
            $data['recurring_weeks'] = null;
        }
        if (($data['format'] ?? null) === TeachingFormat::Online->value) {
            $data['area'] = null;
        }
        $data['level_ids'] = array_values(array_map('intval', (array) ($data['level_ids'] ?? [])));

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function check(TutorProfile $profile, OfferingType $type, array $data): void
    {
        $errors = [];

        if ($type->isGroup() && (int) ($data['capacity'] ?? 0) < 2) {
            $errors['capacity'] = 'A group lesson needs room for at least 2 learners.';
        }
        if ($type === OfferingType::Package && empty($data['package_lessons'])) {
            $errors['package_lessons'] = 'Say how many lessons the package holds (2 or more).';
        }
        if ($type === OfferingType::RecurringWeekly && empty($data['recurring_weeks'])) {
            $errors['recurring_weeks'] = 'Say how many weeks the weekly lessons run for (2 or more).';
        }
        $format = TeachingFormat::from($data['format']);
        if ($format->allowsInPerson() && blank($data['area'] ?? null)) {
            $errors['area'] = 'Say where in-person lessons happen (a suburb or town).';
        }

        $teachable = $profile->subjects()->pluck('subjects.id')->map(fn ($id) => (int) $id)->all();
        $outside = array_diff(array_map('intval', $data['subject_ids'] ?? []), $teachable);
        if ($outside !== []) {
            $errors['subject_ids'] = 'Offerings can only cover subjects in your teaching scope. Add the subject to your scope first.';
        }

        if (($data['level_ids'] ?? []) !== []) {
            if (! $this->academic->isAvailable()) {
                $errors['level_ids'] = 'Academic levels are not available yet. Leave levels empty for now (the offering then covers your whole teaching scope).';
            } else {
                foreach ($data['level_ids'] as $levelId) {
                    if ($this->academic->levelRank((int) $levelId) === null) {
                        $errors['level_ids'] = 'One of the levels does not exist.';
                        break;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
