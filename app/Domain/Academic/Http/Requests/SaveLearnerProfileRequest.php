<?php

namespace App\Domain\Academic\Http\Requests;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\SubjectOffering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveLearnerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $year = (int) now()->format('Y');
        $activeLanguage = Rule::exists('languages', 'code')->where('is_active', true);

        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'academic_level_id' => ['required', 'integer', Rule::exists('academic_levels', 'id')->where('is_active', true)],
            'subjects' => ['array', 'max:20'],
            'subjects.*' => ['integer', 'distinct'],
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('is_active', true)],
            'birth_year' => ['required', 'integer', 'between:'.($year - 100).','.$year],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'teaching' => ['array', 'max:5'],
            'teaching.*' => ['string', 'distinct', $activeLanguage],
            'understands' => ['array', 'max:10'],
            'understands.*' => ['string', 'distinct', $activeLanguage],
            'learning' => ['array', 'max:5'],
            'learning.*' => ['string', 'distinct', $activeLanguage],
            'bilingual_ok' => ['nullable', Rule::in(['0', '1'])],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $level = AcademicLevel::query()->with('curriculum')->find($this->integer('academic_level_id'));
            $curriculum = $level?->curriculum;
            if (! $curriculum || ! $curriculum->is_active) {
                $validator->errors()->add('academic_level_id', __('academic.validation.level_not_in_curriculum'));

                return;
            }
            if ($curriculum->country_id !== null && $curriculum->country_id !== $this->integer('country_id')) {
                $validator->errors()->add('academic_level_id', __('academic.validation.curriculum_not_in_country'));
            }

            $subjectIds = array_map('intval', $this->input('subjects', []));
            $offered = SubjectOffering::query()->active()->where('academic_level_id', $level->id)->whereIn('subject_id', $subjectIds)->count();
            if ($offered !== count($subjectIds)) {
                $validator->errors()->add('subjects', __('academic.validation.subject_not_at_level'));
            }

            $born = CarbonImmutable::create($this->integer('birth_year'), $this->integer('birth_month'), 1);
            if ($born->greaterThan(now()->startOfMonth())) {
                $validator->errors()->add('birth_month', __('academic.validation.birth_month_future'));
            }
        }];
    }
}
