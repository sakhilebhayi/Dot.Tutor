<?php

namespace App\Domain\Marketplace\Support;

use App\Domain\Marketplace\Enums\OfferingType;
use Illuminate\Validation\Rule;

/**
 * What a learner needs, as structured data (brief section 6): subject, level,
 * curriculum, teaching languages, format and place, an availability window,
 * a budget and the kind of help. Turning a sentence like "Grade 11 Maths in
 * isiZulu after school" into this is a separate step (wave 2, ai agent).
 */
final readonly class LearningNeed
{
    /**
     * @param  list<string>  $languages  BCP 47 codes in order of preference
     * @param  list<int>  $weekdays  ISO-8601 weekdays (1 Monday .. 7 Sunday); empty = any day
     */
    public function __construct(
        public int $subjectId,
        public ?int $levelId = null,
        public ?int $curriculumId = null,
        public array $languages = [],
        public bool $bilingualOk = false,
        public string $format = 'either',
        public ?string $area = null,
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public array $weekdays = [],
        public ?string $fromTime = null,
        public ?string $toTime = null,
        public string $timezone = 'Africa/Johannesburg',
        public ?int $budgetPerHourMinor = null,
        public ?string $currency = null,
        public ?OfferingType $helpType = null,
        public int $durationMinutes = 60,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'level_id' => ['nullable', 'integer', 'min:1'],
            'curriculum_id' => ['nullable', 'integer', 'min:1'],
            'languages' => ['nullable', 'array', 'max:5'],
            'languages.*' => ['string', 'max:20', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
            'bilingual_ok' => ['nullable', 'boolean'],
            'format' => ['nullable', Rule::in(['online', 'in_person', 'either'])],
            'area' => ['nullable', 'string', 'max:120'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i', 'after:from_time'],
            'timezone' => ['nullable', 'timezone:all'],
            'budget_per_hour_minor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'help_type' => ['nullable', Rule::in(OfferingType::values())],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240', 'multiple_of:15'],
        ];
    }

    /** @param  array<string, mixed>  $data  validated by rules() */
    public static function fromArray(array $data): self
    {
        return new self(
            subjectId: (int) $data['subject_id'],
            levelId: isset($data['level_id']) ? (int) $data['level_id'] : null,
            curriculumId: isset($data['curriculum_id']) ? (int) $data['curriculum_id'] : null,
            languages: array_values(array_map(fn ($c) => strtolower((string) $c), (array) ($data['languages'] ?? []))),
            bilingualOk: (bool) ($data['bilingual_ok'] ?? false),
            format: (string) ($data['format'] ?? 'either'),
            area: $data['area'] ?? null,
            fromDate: $data['from_date'] ?? null,
            toDate: $data['to_date'] ?? null,
            weekdays: array_values(array_map('intval', (array) ($data['weekdays'] ?? []))),
            fromTime: $data['from_time'] ?? null,
            toTime: $data['to_time'] ?? null,
            timezone: (string) ($data['timezone'] ?? config('tutor.timezone')),
            budgetPerHourMinor: isset($data['budget_per_hour_minor']) ? (int) $data['budget_per_hour_minor'] : null,
            currency: $data['currency'] ?? null,
            helpType: isset($data['help_type']) ? OfferingType::from($data['help_type']) : null,
            durationMinutes: (int) ($data['duration_minutes'] ?? 60),
        );
    }

    /** A stable fingerprint of the need, used to break score ties fairly but deterministically. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode(get_object_vars($this) + ['help' => $this->helpType?->value]) ?: '');
    }

    public function hasWindow(): bool
    {
        return $this->fromDate !== null || $this->toDate !== null || $this->weekdays !== [] || $this->fromTime !== null || $this->toTime !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'level_id' => $this->levelId,
            'curriculum_id' => $this->curriculumId,
            'languages' => $this->languages,
            'bilingual_ok' => $this->bilingualOk,
            'format' => $this->format,
            'area' => $this->area,
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'weekdays' => $this->weekdays,
            'from_time' => $this->fromTime,
            'to_time' => $this->toTime,
            'timezone' => $this->timezone,
            'budget_per_hour_minor' => $this->budgetPerHourMinor,
            'currency' => $this->currency,
            'help_type' => $this->helpType?->value,
            'duration_minutes' => $this->durationMinutes,
        ];
    }
}
