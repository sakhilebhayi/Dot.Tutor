<?php

namespace App\Domain\Ai\Guardrails;

/**
 * Everything the assistant is allowed to know about the person it answers: an age band, the academic position and
 * the answer language. Deliberately absent: name, email, date of birth, school, location, guardian details (D-906).
 */
final readonly class LearnerContext
{
    /** @param  list<string>  $subjects */
    public function __construct(
        public int $userId,
        public string $band,
        public bool $ageKnown,
        public ?string $curriculum,
        public ?string $level,
        public ?string $stage,
        public array $subjects,
        public string $language,
        public string $languageName,
    ) {}

    public function isMinorBehaviour(): bool
    {
        return $this->band !== 'adult';
    }

    /** What is logged with each interaction (no personal data beyond the band). @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'band' => $this->band,
            'age_known' => $this->ageKnown,
            'curriculum' => $this->curriculum,
            'level' => $this->level,
            'stage' => $this->stage,
            'language' => $this->language,
        ];
    }
}
