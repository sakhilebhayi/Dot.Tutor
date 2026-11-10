<?php

namespace App\Domain\Ai\Guardrails;

/**
 * A reason the assistant did not answer, in plain words. Codes are stable (logged and returned in JSON).
 */
final readonly class Refusal
{
    private const MESSAGES = [
        'not_configured' => 'The AI assistant is not configured on this platform yet. Nothing was sent anywhere. Ask a tutor instead.',
        'account_suspended' => 'Your account is suspended, so the assistant is not available.',
        'guardian_consent_required' => 'A parent or guardian needs to link to your account and give consent before you can use the AI assistant.',
        'band_disabled' => 'The AI assistant is not available for your age group.',
        'not_a_tutor' => 'The tutor tools are for people with a tutor profile.',
        'daily_limit' => 'You have reached today\'s limit for the AI assistant. It resets tomorrow. A tutor can help in the meantime.',
        'daily_cost_limit' => 'You have reached today\'s limit for the AI assistant. It resets tomorrow. A tutor can help in the meantime.',
        'monthly_budget' => 'The AI assistant has reached this month\'s budget for the whole platform and is paused until next month.',
        'assessment_in_progress' => 'You are taking a timed assessment. The assistant is paused until you submit it, so the result stays your own work.',
        'assessment_question' => 'This looks like a question from an assessment you have been set. The assistant will not answer it. After you submit, it can help you understand your mistakes.',
        'integrity_request' => 'The assistant does not do assessed work for anyone. It can explain the idea behind the question, step by step, so you can do it yourself.',
        'not_found' => 'That item was not found, or you do not have access to it.',
        'not_your_attempt' => 'You can only ask about your own submitted attempts.',
        'attempt_not_submitted' => 'Submit the attempt first. The assistant explains mistakes only after submission.',
        'answer_was_correct' => 'You answered this question correctly, so there is no mistake to explain.',
        'resource_not_readable' => 'The assistant can read PDF and image resources only, within the size limit.',
        'no_evidence' => 'There is no marked work from your students yet, so there is nothing to summarise.',
        'no_candidates' => 'The library has no published resources for this subject and level yet, so there is nothing to suggest.',
        'not_your_bank' => 'You can only draft questions into your own question banks.',
        'not_your_course' => 'You can only ask about your own courses.',
        'too_long' => 'This text is too long to translate in one go.',
        'nothing_to_translate' => 'There is no text in that field to translate.',
        'same_language' => 'The text is already in that language.',
        'refused_by_model' => 'The assistant declined to answer this request. A tutor can help instead.',
        'notation_lost' => 'The machine translation changed or dropped formulas or numbers, so it was not saved. Try again or ask a person to translate it.',
        'provider_auth' => 'The AI service refused the platform\'s key. The operators have been told in the logs.',
        'provider_model' => 'The configured AI model is not available. The operators have been told in the logs.',
        'provider_busy' => 'The AI service is busy. Try again in a minute.',
        'provider_unreachable' => 'The AI service could not be reached. Try again in a minute.',
        'provider_rejected' => 'The AI service could not process this request.',
        'provider_error' => 'The AI service answered with an error. Try again later.',
        'malformed_output' => 'The AI answer could not be read. Try again.',
    ];

    public function __construct(public string $code, public ?string $detail = null) {}

    public function message(): string
    {
        return self::messageFor($this->code).($this->detail ? ' '.$this->detail : '');
    }

    public static function messageFor(string $code): string
    {
        return self::MESSAGES[$code] ?? 'The assistant could not answer this request.';
    }
}
