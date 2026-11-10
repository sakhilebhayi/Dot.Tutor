<?php

namespace App\Domain\Integration\Http;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\TeachingProficiency;
use App\Domain\Academic\Support\TeachingLanguage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A tutor declares the languages they teach in (academic LanguageProfile, role can_teach_in) from the marketplace
 * workspace. Matching reads exactly these. Declared levels are self-declared until an operator verifies them on
 * /operator/academic/teaching-languages; changing a claim drops its verification.
 */
class TeachingLanguagesController extends Controller
{
    public function show(Request $request, LanguageProfile $languages): JsonResponse
    {
        abort_unless($request->user()->tutorProfile()->exists(), 403, 'Only tutors declare teaching languages.');

        return response()->json(['data' => $this->present($languages->teachingLanguages($request->user()))]);
    }

    public function update(Request $request, LanguageProfile $languages): JsonResponse
    {
        abort_unless($request->user()->tutorProfile()->exists(), 403, 'Only tutors declare teaching languages.');

        $data = $request->validate([
            'languages' => ['present', 'array', 'max:8'],
            'languages.*.code' => ['required', 'string', 'distinct', Rule::exists('languages', 'code')->where('is_active', true)],
            'languages.*.proficiency' => ['required', Rule::enum(TeachingProficiency::class)],
        ]);

        $languages->syncTeachingLanguages($request->user(), array_map(
            fn (array $l) => ['code' => $l['code'], 'proficiency' => $l['proficiency']], $data['languages']));

        return response()->json(['data' => $this->present($languages->teachingLanguages($request->user()))]);
    }

    /**
     * @param  list<TeachingLanguage>  $list
     * @return list<array<string, mixed>>
     */
    private function present(array $list): array
    {
        return array_map(fn (TeachingLanguage $l) => [
            'code' => $l->code, 'name' => $l->nameEn, 'proficiency' => $l->proficiency->value,
            'verified' => $l->verified, 'label' => $l->verified ? 'Verified by Dot.Tutor' : 'Self-declared',
        ], $list);
    }
}
