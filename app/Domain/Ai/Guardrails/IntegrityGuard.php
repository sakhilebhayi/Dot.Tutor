<?php

namespace App\Domain\Ai\Guardrails;

use App\Domain\Ai\Seams\LearningRecords;
use App\Models\User;

/**
 * Academic integrity, enforced in code before anything is sent (brief sections 14 and 15):
 * 1. While a learner is in a timed assessment, the assistant is paused for them entirely.
 * 2. A request that reproduces a question from an assessment they must answer on their own (in progress, assigned
 *    and not passed, or a quiz of a course they are enrolled in and have not passed) is refused.
 * 3. A request that asks for assessed work to be done ("do my assignment", "answers to my exam") is refused, and the
 *    learner is offered "help me understand" instead.
 * The model is also told not to complete assessments; this class is the part that does not depend on the model.
 */
class IntegrityGuard
{
    /** Share of a question's words that must appear in the request to call it a copy. */
    private const CONTAINMENT = 0.7;

    private const MIN_QUESTION_WORDS = 5;

    private const CHEATING_PATTERNS = [
        '/\b(do|write|complete|finish|solve)\s+(my|our|this|the)\s+(home\s*work|assignment|exam|test|quiz|essay|project|assessment|coursework)\s+for\s+me\b/iu',
        '/\b(answers?|memo|memorandum|solutions?)\s+(to|for|of)\s+(my|our|the|this|today\'?s|tomorrow\'?s)\s+(exam|test|quiz|assessment|paper|assignment)\b/iu',
        '/\b(i\'?m|i am|we\'?re|we are)\s+(in|writing|taking|doing|sitting)\s+(an?|my|the|our)\s+(exam|test|quiz|assessment)\s+(right\s+)?now\b/iu',
        '/\bwrite\s+(my|an?)\s+essay\s+(for|that)\s+i\s+(can|will)\s+(submit|hand\s+in)\b/iu',
        '/\bdoen\s+my\s+(huiswerk|opdrag|toets|eksamen)\s+vir\s+my\b/iu',
        '/\b(antwoorde|memo)\s+vir\s+(my|die)\s+(toets|eksamen|opdrag)\b/iu',
        '/\bwithout\s+(the\s+)?(teacher|lecturer|tutor|invigilator)\s+knowing\b/iu',
    ];

    public function __construct(private readonly LearningRecords $learning) {}

    public function check(User $user, string $text): ?Refusal
    {
        if ($this->learning->timedAttemptsInProgress($user)->isNotEmpty()) {
            return new Refusal('assessment_in_progress');
        }
        if ($this->asksForAssessedWork($text)) {
            return new Refusal('integrity_request');
        }
        $request = self::words($text);
        if ($request === []) {
            return null;
        }
        $requestSet = array_flip($request);
        $requestFlat = ' '.implode(' ', $request).' ';
        foreach ($this->learning->protectedQuestions($user) as $question) {
            $words = self::words((string) $question->prompt);
            if (count($words) < self::MIN_QUESTION_WORDS) {
                // A very short question ("Solve 2x+3=7") is protected only when it appears whole.
                if ($words !== [] && str_contains($requestFlat, ' '.implode(' ', $words).' ')) {
                    return new Refusal('assessment_question');
                }

                continue;
            }
            $hits = count(array_filter(array_unique($words), fn (string $w) => isset($requestSet[$w])));
            if ($hits / count(array_unique($words)) >= self::CONTAINMENT) {
                return new Refusal('assessment_question');
            }
        }

        return null;
    }

    public function asksForAssessedWork(string $text): bool
    {
        foreach (self::CHEATING_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Lower-cased words and numbers; operators kept as their own tokens so "2x+3=7" still matches itself. @return list<string> */
    public static function words(string $text): array
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/([=+\-*\/^<>])/u', ' $1 ', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}=+\-*\/^<>.,]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_filter(array_map(fn (string $p) => trim($p, '.,'), $parts), fn (string $p) => $p !== ''));
    }
}
