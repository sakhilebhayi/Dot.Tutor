<?php

namespace App\Domain\Student\Http\Composers;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Safety\Services\ContactPrivacy;
use App\Models\TutorProfile;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Presentation data for the find screen (the marketplace's /find-a-tutor). The marketplace controller does the
 * matching; this adds the form's choices, the learner's saved defaults (shown as such) and, for each result, the
 * tutor's teaching languages and the matcher's own terms turned into "fits / does not fit" lines (D-1107).
 */
class FindComposer
{
    /** Terms in the order they are explained, with the word used when a term is met. */
    private const TERMS = ['scope', 'curriculum', 'language', 'availability', 'budget', 'help_type', 'reviews', 'new_tutor'];

    public function __construct(private readonly Request $request, private readonly LanguageProfile $languages, private readonly ContactPrivacy $privacy) {}

    public function compose(View $view): void
    {
        $user = $this->request->user();
        $data = $view->getData();
        $result = $data['result'] ?? null;
        $profile = $user ? LearnerProfile::query()->where('user_id', $user->id)->first() : null;
        $searched = $this->request->filled('subject_id');

        // Defaults come from the saved learner profile only when the learner has not searched yet.
        $saved = [
            'level_id' => $profile?->academic_level_id,
            'languages' => $user ? $this->languages->codes($user, LanguageRole::PrefersTeaching) : [],
            'bilingual_ok' => (bool) $profile?->bilingual_ok,
        ];
        $form = [
            'need' => (string) $this->request->query('need', ''),
            'subject_id' => $this->request->integer('subject_id') ?: null,
            'level_id' => $this->request->integer('level_id') ?: ($searched ? null : $saved['level_id']),
            'languages' => $this->listParam('languages') ?: ($searched ? [] : $saved['languages']),
            'bilingual_ok' => $this->request->has('bilingual_ok') ? $this->request->boolean('bilingual_ok') : (! $searched && $saved['bilingual_ok']),
            'format' => (string) $this->request->query('format', 'either'),
            'area' => (string) $this->request->query('area', ''),
            'budget_rand' => (string) $this->request->query('budget_rand', ''),
            'help_type' => (string) $this->request->query('help_type', ''),
            'weekdays' => array_map('intval', $this->listParam('weekdays')),
            'from_time' => (string) $this->request->query('from_time', ''),
            'to_time' => (string) $this->request->query('to_time', ''),
        ];

        $levels = Curriculum::query()->active()->orderBy('sort')->with(['levels' => fn ($q) => $q->active()])->get()
            ->flatMap(fn ($c) => $c->levels->map(fn ($l) => ['value' => $l->id, 'label' => $l->name, 'group' => $c->label(), 'curriculum_id' => $c->id]))
            ->values()->all();

        $view->with([
            'form' => $form,
            'levelOptions' => $levels,
            'languageOptions' => Language::query()->active()->orderBy('sort')->get(['code', 'name_en', 'name_native']),
            'understood' => session('student.understood', []),
            'needNotes' => session('student.need_notes', []),
            'foundSubject' => session('student.found_subject'),
            'rows' => $result ? $this->rows($result, $form['languages']) : [],
        ]);
    }

    /** @return list<string> */
    private function listParam(string $key): array
    {
        $value = $this->request->query($key);
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== ''));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $wanted
     * @return list<array<string, mixed>>
     */
    private function rows(array $result, array $wanted): array
    {
        $ids = array_map(fn ($r) => $r['tutor']['id'], $result['results']);
        $profiles = TutorProfile::query()->whereIn('id', $ids ?: [0])->with('user')->get(['id', 'user_id', 'teaches_online', 'teaches_in_person', 'in_person_area'])->keyBy('id');
        $teaching = $this->languages->teachingLanguagesFor($profiles->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->values()->all());
        $names = Language::query()->pluck('name_native', 'code');

        return array_map(function (array $match) use ($profiles, $teaching, $names, $wanted) {
            $profile = $profiles[$match['tutor']['id']] ?? null;
            $terms = collect($match['terms'])->keyBy('term');
            $lines = [];
            foreach (self::TERMS as $term) {
                $t = $terms[$term] ?? null;
                if ($t === null || $t['note'] === null) {
                    continue;
                }
                // A term the learner did not ask about (weight 0 value 0 with no note) is skipped above. The new-tutor
                // note is information, not a gap. Reviews below the minimum are shown as a gap so it is never hidden.
                $lines[] = ['text' => $t['note'], 'met' => $term === 'new_tutor' ? true : $t['value'] >= 0.5];
            }
            foreach ($match['reasons'] as $reason) {
                if (! collect($lines)->contains('text', $reason) && ! in_array($reason, collect($match['terms'])->pluck('note')->all(), true)) {
                    array_unshift($lines, ['text' => $reason, 'met' => true]);
                }
            }

            // The headline is the tutor's free text: contact details are hidden from guests, minors and unknown ages
            // through safety's view_contact rule, as on the storefront (S-08; R-47 polish D-2003, the page is public).
            if (filled($match['tutor']['headline'] ?? null) && $profile?->user !== null) {
                $match['tutor']['headline'] = $this->privacy->profileText($this->request->user(), $profile->user, (string) $match['tutor']['headline'])['text'];
            }

            return $match + [
                'lines' => $lines,
                'formats' => $profile ? array_values(array_filter([$profile->teaches_online ? 'Online' : null, $profile->teaches_in_person ? 'In person'.($profile->in_person_area ? ' ('.$profile->in_person_area.')' : '') : null])) : [],
                'teaching_languages' => collect($profile && $profile->user_id ? ($teaching[$profile->user_id] ?? []) : [])->map(fn ($l) => [
                    'code' => $l->code, 'name' => $l->nameEn, 'endonym' => $names[$l->code] ?? $l->nameEn, 'verified' => $l->verified, 'match' => in_array($l->code, $wanted, true),
                ])->all(),
            ];
        }, $result['results']);
    }
}
