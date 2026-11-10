<?php

namespace App\Domain\Learning\Services;

use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use Illuminate\Validation\ValidationException;

/**
 * Server-side marking. Multi-select is all-or-nothing (no partial credit), so ticking every option never earns marks.
 * Numeric answers accept a comma or a point as the decimal mark and are compared within the question's tolerance.
 * Short text compares after trimming, collapsing spaces, ignoring case and a trailing full stop.
 */
class Marker
{
    /** @return array{correct: bool, marks: int} */
    public function mark(Question $question, mixed $response): array
    {
        $correct = match ($question->type) {
            QuestionType::SingleChoice => is_string($response) && $response === ($question->answer['key'] ?? null),
            QuestionType::MultiSelect => $this->sameSet($response, $question->answer['keys'] ?? []),
            QuestionType::Numeric => $this->numericMatches($response, $question->answer),
            QuestionType::ShortText => $this->textMatches($response, $question->answer['accepted'] ?? []),
        };

        return ['correct' => $correct, 'marks' => $correct ? (int) $question->marks : 0];
    }

    /**
     * Validate and normalise an answer key for a question type (used when authoring).
     *
     * @param  list<array{key: string, text: string}>|null  $options
     * @param  array<string, mixed>  $answer
     * @return array{options: ?list<array{key: string, text: string}>, answer: array<string, mixed>}
     */
    public function normaliseKey(QuestionType $type, ?array $options, array $answer): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['answer' => $message]);

        if (in_array($type, [QuestionType::SingleChoice, QuestionType::MultiSelect], true)) {
            $options = array_values(array_filter($options ?? [], fn ($o) => is_array($o) && trim((string) ($o['key'] ?? '')) !== '' && trim((string) ($o['text'] ?? '')) !== ''));
            $options = array_map(fn ($o) => ['key' => trim((string) $o['key']), 'text' => trim((string) $o['text'])], $options);
            $keys = array_column($options, 'key');
            if (count($options) < 2 || count(array_unique($keys)) !== count($keys)) {
                $fail('Give at least two options, each with its own key.');
            }
            if ($type === QuestionType::SingleChoice) {
                $key = (string) ($answer['key'] ?? '');
                in_array($key, $keys, true) || $fail('The correct answer must be one of the options.');

                return ['options' => $options, 'answer' => ['key' => $key]];
            }
            $chosen = array_values(array_unique(array_map('strval', (array) ($answer['keys'] ?? []))));
            ($chosen !== [] && array_diff($chosen, $keys) === []) || $fail('The correct answers must be options of this question.');
            sort($chosen);

            return ['options' => $options, 'answer' => ['keys' => $chosen]];
        }

        if ($type === QuestionType::Numeric) {
            $value = $this->toNumber($answer['value'] ?? null);
            $tolerance = $this->toNumber($answer['tolerance'] ?? 0);
            ($value !== null && $tolerance !== null && $tolerance >= 0) || $fail('Give the correct number and a tolerance of zero or more.');

            return ['options' => null, 'answer' => ['value' => $value, 'tolerance' => $tolerance]];
        }

        $accepted = array_values(array_filter(array_map(fn ($a) => trim((string) $a), (array) ($answer['accepted'] ?? []))));
        $accepted !== [] || $fail('Give at least one accepted answer.');

        return ['options' => null, 'answer' => ['accepted' => $accepted]];
    }

    private function sameSet(mixed $response, array $expected): bool
    {
        if (! is_array($response)) {
            return false;
        }
        $given = array_values(array_unique(array_map('strval', $response)));
        sort($given);
        $expected = array_map('strval', $expected);
        sort($expected);

        return $given === $expected;
    }

    private function numericMatches(mixed $response, array $key): bool
    {
        $value = $this->toNumber($response);

        return $value !== null && abs($value - (float) $key['value']) <= (float) ($key['tolerance'] ?? 0) + 1e-9;
    }

    private function textMatches(mixed $response, array $accepted): bool
    {
        if (! is_string($response) && ! is_numeric($response)) {
            return false;
        }
        $given = $this->normaliseText((string) $response);

        return $given !== '' && in_array($given, array_map(fn ($a) => $this->normaliseText((string) $a), $accepted), true);
    }

    private function normaliseText(string $text): string
    {
        return rtrim(mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? ''), '.');
    }

    private function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        return is_numeric($clean) ? (float) $clean : null;
    }
}
