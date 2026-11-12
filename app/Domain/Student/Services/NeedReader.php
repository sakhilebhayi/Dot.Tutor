<?php

namespace App\Domain\Student\Services;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Services\NeedParser;
use App\Models\User;

/**
 * Turns "Grade 11 Maths in isiZulu after school, under R250" into the matcher's need (D-1105).
 *
 * The academic NeedParser (deterministic, no AI) finds the level, subject and teaching language. This class adds a
 * few plain-word signals the matcher also takes (budget in rand, online or in person, the kind of help, after school
 * or weekends) and fills gaps from the learner's saved profile, saying which came from where. Nothing is guessed
 * silently: every value lands in an editable field on the find screen with its source.
 */
class NeedReader
{
    public function __construct(private readonly NeedParser $parser, private readonly LanguageProfile $languages) {}

    /**
     * @return array{query: array<string, mixed>, understood: list<array{label: string, value: string, from: string}>,
     *     found_subject: bool, notes: list<string>, parsed: array<string, mixed>}
     */
    public function read(string $text, User $user): array
    {
        $text = trim(mb_substr($text, 0, 500));
        $profile = LearnerProfile::query()->where('user_id', $user->id)->first();
        $parsed = $this->parser->parse($text, $profile?->curriculum_id);
        $query = ['need' => $text];
        $understood = [];
        $notes = [];

        // Level: the parser's preferred-curriculum match first, else its first, else the saved profile.
        $levelRow = collect($parsed['levels'])->firstWhere('preferred', true) ?? ($parsed['levels'][0] ?? null);
        $level = $levelRow ? AcademicLevel::query()->with('curriculum')->find($levelRow['id']) : null;
        $levelFrom = 'your words';
        if ($level === null && $profile?->academic_level_id) {
            $level = AcademicLevel::query()->with('curriculum')->find($profile->academic_level_id);
            $levelFrom = 'your learning profile';
        }
        if ($level) {
            $query['level_id'] = $level->id;
            $query['curriculum_id'] = $level->curriculum_id;
            $understood[] = ['label' => 'Level', 'value' => $level->name.' ('.$level->curriculum->label().')', 'from' => $levelFrom];
        }

        $subject = $parsed['subjects'][0] ?? null;
        if ($subject) {
            $query['subject_id'] = $subject['id'];
            $understood[] = ['label' => 'Subject', 'value' => $subject['name'], 'from' => 'your words'];
        }

        // Teaching language: stated in the words, else the profile's preferred teaching languages. Never the interface.
        $stated = collect($parsed['teaching_languages'])->where('certainty', 'stated')->pluck('code')->all();
        if ($stated !== []) {
            $query['languages'] = $stated;
            $understood[] = ['label' => 'Taught in', 'value' => collect($parsed['teaching_languages'])->where('certainty', 'stated')->pluck('name')->implode(', '), 'from' => 'your words'];
        } else {
            $saved = $this->languages->codes($user, LanguageRole::PrefersTeaching);
            if ($saved !== []) {
                $query['languages'] = $saved;
                $understood[] = ['label' => 'Taught in', 'value' => strtoupper(implode(', ', $saved)), 'from' => 'your learning profile'];
            }
        }
        if ($profile?->bilingual_ok) {
            $query['bilingual_ok'] = 1;
        }

        $lower = mb_strtolower($text);
        if (preg_match('/(?:under|below|less than|max(?:imum)?|up to|onder|minder as)\s*r\s?(\d[\d\s]{0,6})/u', $lower, $m)
            || preg_match('/r\s?(\d[\d\s]{0,6})\s*(?:or less|max|an hour|per hour|p\/h)/u', $lower, $m)) {
            $rand = (int) preg_replace('/\D/', '', $m[1]);
            if ($rand > 0 && $rand <= 100000) {
                $query['budget_rand'] = $rand;
                $understood[] = ['label' => 'Budget', 'value' => 'R'.$rand.' an hour', 'from' => 'your words'];
            }
        }
        if (preg_match('/\b(in person|in-person|face to face|at home|my house)\b/u', $lower)) {
            $query['format'] = 'in_person';
            $understood[] = ['label' => 'Format', 'value' => 'In person', 'from' => 'your words'];
        } elseif (preg_match('/\b(online|video|zoom|on line|aanlyn)\b/u', $lower)) {
            $query['format'] = 'online';
            $understood[] = ['label' => 'Format', 'value' => 'Online', 'from' => 'your words'];
        }

        $helpTypes = [
            'exam_prep' => '/\b(exam|exams|matric finals|finals|test|tests|eksamen|toets)\b/u',
            'homework_help' => '/\b(homework|huiswerk|assignment)\b/u',
            'revision' => '/\b(revision|revise|hersiening)\b/u',
            'group' => '/\b(group|groep|with friends|study group)\b/u',
        ];
        foreach ($helpTypes as $type => $pattern) {
            if (preg_match($pattern, $lower)) {
                $query['help_type'] = $type;
                $understood[] = ['label' => 'Kind of help', 'value' => str_replace('_', ' ', ucfirst($type === 'exam_prep' ? 'exam preparation' : $type)), 'from' => 'your words'];
                break;
            }
        }

        if (preg_match('/\b(after school|na skool|afternoons?)\b/u', $lower)) {
            $query['weekdays'] = [1, 2, 3, 4, 5];
            $query['from_time'] = '14:00';
            $query['to_time'] = '18:00';
            $understood[] = ['label' => 'When', 'value' => 'Weekdays 14:00 to 18:00', 'from' => 'your words ("after school")'];
        } elseif (preg_match('/\b(weekends?|saturdays?|sundays?|naweek)\b/u', $lower)) {
            $query['weekdays'] = [6, 7];
            $understood[] = ['label' => 'When', 'value' => 'Saturdays and Sundays', 'from' => 'your words'];
        }

        foreach ($parsed['notes'] as $note) {
            if (($note['key'] ?? '') === 'not_offered') {
                $notes[] = ($note['subject'] ?? 'That subject').' is not offered at '.($note['level'] ?? 'that level').' in the curriculum we know. You can still search.';
            }
        }

        return [
            'query' => $query,
            'understood' => $understood,
            'found_subject' => $subject !== null,
            'notes' => $notes,
            'parsed' => $parsed,
        ];
    }
}
