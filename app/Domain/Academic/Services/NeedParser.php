<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use Illuminate\Support\Str;

/**
 * Turns "I need help with Grade 11 Mathematics in isiZulu" into level, subject
 * and teaching-language CANDIDATES from the seeded data. Deterministic: names,
 * aliases and common abbreviations in several languages, grade patterns
 * ("Grade 11", "Gr11", "Graad 11", "ibanga 11", "matric"), and teaching-language
 * markers ("in", "em", "kwa", "ngesiZulu"; not "en", which is "and" in Afrikaans). No AI, no network.
 *
 * It proposes; the learner confirms. A language named without a marker is
 * ambiguous ("help with Afrikaans" is probably the subject), so it comes back
 * both as language-subject candidates and as a "possible" teaching language.
 */
class NeedParser
{
    private const MAX_PHRASE_TOKENS = 6;

    /** Words that, right before a language name, mean "taught in". */
    private const TEACHING_MARKERS = ['in', 'em', 'kwa', 'through', 'using'];

    private const GRADE_WORDS = ['grade', 'grades', 'gr', 'graad', 'ibanga', 'grado', 'classe', 'darasa'];

    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
        'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
        'een' => 1, 'twee' => 2, 'drie' => 3, 'vier' => 4, 'vyf' => 5, 'ses' => 6, 'sewe' => 7, 'agt' => 8,
        'nege' => 9, 'tien' => 10, 'elf' => 11, 'twaalf' => 12,
    ];

    /** @var array<string, list<array{type: string, id: int}>>|null */
    private ?array $index = null;

    /**
     * @return array{
     *     input: string,
     *     levels: list<array{id: int, name: string, curriculum: string, curriculum_code: string, preferred: bool, matched: string}>,
     *     subjects: list<array{id: int, name: string, matched: string, via: string}>,
     *     teaching_languages: list<array{code: string, name: string, matched: string, certainty: string}>,
     *     offerings: list<array{id: int, level_id: int, subject_id: int}>,
     *     notes: list<array{key: string, subject?: string, level?: string}>
     * }
     */
    public function parse(string $text, ?int $preferredCurriculumId = null): array
    {
        $text = Str::limit($text, 500, '');
        $tokens = $this->tokens($text);

        [$tokens, $gradeMatches] = $this->extractGrades($tokens);

        $levelHits = [];
        $subjectHits = [];
        $languageHits = [];

        foreach ($gradeMatches as [$number, $matched]) {
            foreach (AcademicLevel::query()->active()->where('code', "grade-{$number}")->pluck('id') as $id) {
                $levelHits[$id] = $matched;
            }
        }

        $index = $this->index();
        $count = count($tokens);
        for ($i = 0; $i < $count;) {
            $found = false;
            for ($length = min(self::MAX_PHRASE_TOKENS, $count - $i); $length >= 1; $length--) {
                $phrase = implode(' ', array_slice($tokens, $i, $length));
                if (! isset($index[$phrase])) {
                    continue;
                }

                $before = $tokens[$i - 1] ?? null;
                foreach ($index[$phrase] as $entry) {
                    match ($entry['type']) {
                        'level' => $levelHits[$entry['id']] ??= $phrase,
                        'subject' => $subjectHits[$entry['id']] ??= ['matched' => $phrase, 'via' => 'name'],
                        'language' => $languageHits[$entry['id']] ??= [
                            'matched' => $phrase,
                            'marked' => in_array($before, self::TEACHING_MARKERS, true) || str_starts_with($phrase, 'nge'),
                        ],
                    };
                }
                $i += $length;
                $found = true;
                break;
            }
            if (! $found) {
                $i++;
            }
        }

        // A language phrase that is ALSO a subject alias ("afrikaans", "english") was recorded as both.
        // With a teaching marker it is only the teaching language.
        $languages = Language::query()->whereIn('id', array_keys($languageHits))->get()->keyBy('id');
        foreach ($languageHits as $languageId => $hit) {
            if ($hit['marked']) {
                $subjectHits = array_filter($subjectHits, fn (array $s) => $s['matched'] !== $hit['matched']);
            } else {
                // Unmarked: the learner may want to learn the language itself. Offer its language subjects.
                foreach (Subject::query()->canonical()->where('is_active', true)->where('language_id', $languageId)->pluck('id') as $subjectId) {
                    $subjectHits[$subjectId] ??= ['matched' => $hit['matched'], 'via' => 'language'];
                }
            }
        }

        $levels = AcademicLevel::query()->with('curriculum')->whereIn('id', array_keys($levelHits))->get()
            ->sortBy(fn (AcademicLevel $l) => [$l->curriculum_id === $preferredCurriculumId ? 0 : 1, $l->curriculum->sort, $l->position])
            ->values();
        $subjects = Subject::query()->whereIn('id', array_keys($subjectHits))->where('is_active', true)->orderBy('name')->get();

        $offerings = SubjectOffering::query()->active()
            ->whereIn('academic_level_id', $levels->pluck('id'))
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->get(['id', 'academic_level_id', 'subject_id']);

        $notes = [];
        if ($levels->isNotEmpty() && $subjects->isNotEmpty()) {
            // One note per phrase the learner typed, only when nothing it matched is offered at the level.
            foreach ($subjects->groupBy(fn (Subject $s) => $subjectHits[$s->id]['matched']) as $group) {
                if (! $group->contains(fn (Subject $s) => $offerings->contains('subject_id', $s->id))) {
                    $notes[] = ['key' => 'not_offered', 'subject' => $group->first()->name, 'level' => $levels->first()->name];
                }
            }
        }
        if ($levels->isEmpty()) {
            $notes[] = ['key' => 'no_level'];
        }
        if ($subjects->isEmpty()) {
            $notes[] = ['key' => 'no_subject'];
        }

        return [
            'input' => $text,
            'levels' => $levels->map(fn (AcademicLevel $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'curriculum' => $l->curriculum->label(),
                'curriculum_code' => $l->curriculum->code,
                'preferred' => $preferredCurriculumId !== null && $l->curriculum_id === $preferredCurriculumId,
                'matched' => $levelHits[$l->id],
            ])->all(),
            'subjects' => $subjects->map(fn (Subject $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'matched' => $subjectHits[$s->id]['matched'],
                'via' => $subjectHits[$s->id]['via'],
            ])->values()->all(),
            'teaching_languages' => collect($languageHits)->map(fn (array $hit, int $id) => [
                'code' => $languages[$id]->code,
                'name' => $languages[$id]->name_en,
                'matched' => $hit['matched'],
                'certainty' => $hit['marked'] ? 'stated' : 'possible',
            ])->sortBy(fn (array $l) => $l['certainty'] === 'stated' ? 0 : 1)->values()->all(),
            'offerings' => $offerings->map(fn (SubjectOffering $o) => [
                'id' => $o->id, 'level_id' => $o->academic_level_id, 'subject_id' => $o->subject_id,
            ])->values()->all(),
            'notes' => $notes,
        ];
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        $normal = Str::lower(Str::ascii(str_replace(["'", '’'], '', $text)));
        $normal = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', ' ', $normal); // "gr11" -> "gr 11"
        $normal = preg_replace('/[^a-z0-9]+/', ' ', (string) $normal);

        return array_values(array_filter(explode(' ', trim((string) $normal)), fn (string $t) => $t !== ''));
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0: list<string>, 1: list<array{0: int, 1: string}>}
     */
    private function extractGrades(array $tokens): array
    {
        $grades = [];
        $rest = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            $number = $next === null ? null : (ctype_digit($next) ? (int) $next : (self::NUMBER_WORDS[$next] ?? null));

            if (in_array($token, self::GRADE_WORDS, true) && $number !== null && $number >= 1 && $number <= 12) {
                $grades[] = [$number, "{$token} {$next}"];
                $i++;

                continue;
            }
            $rest[] = $token;
        }

        return [$rest, $grades];
    }

    /** @return array<string, list<array{type: string, id: int}>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        $add = function (?string $phrase, string $type, int $id) use (&$index): void {
            $key = $phrase === null ? '' : implode(' ', $this->tokens($phrase));
            if ($key !== '' && ! in_array(['type' => $type, 'id' => $id], $index[$key] ?? [], true)) {
                $index[$key][] = ['type' => $type, 'id' => $id];
            }
        };

        foreach (Subject::query()->canonical()->where('is_active', true)->get(['id', 'name', 'aliases']) as $subject) {
            $add($subject->name, 'subject', $subject->id);
            foreach ($subject->aliases ?? [] as $alias) {
                $add($alias, 'subject', $subject->id);
            }
        }

        foreach (Language::query()->active()->get(['id', 'name_en', 'name_native', 'aliases']) as $language) {
            $add($language->name_en, 'language', $language->id);
            $add($language->name_native, 'language', $language->id);
            foreach ($language->aliases ?? [] as $alias) {
                $add($alias, 'language', $language->id);
            }
        }

        foreach (AcademicLevel::query()->active()->where('code', 'not like', 'grade-%')->get(['id', 'name', 'aliases']) as $level) {
            $add($level->name, 'level', $level->id);
            foreach ($level->aliases ?? [] as $alias) {
                $add($alias, 'level', $level->id);
            }
        }
        // "matric" and other aliases on grade levels.
        foreach (AcademicLevel::query()->active()->where('code', 'like', 'grade-%')->whereNotNull('aliases')->get(['id', 'aliases']) as $level) {
            foreach ($level->aliases ?? [] as $alias) {
                $add($alias, 'level', $level->id);
            }
        }

        return $this->index = $index;
    }
}
