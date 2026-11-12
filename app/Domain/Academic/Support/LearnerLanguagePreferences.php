<?php

namespace App\Domain\Academic\Support;

/**
 * A learner's teaching-language wishes. `teaching` is what they prefer to be
 * taught in; `understands` widens the match; `bilingualOk` null means they did
 * not say. The interface locale is deliberately absent.
 */
final readonly class LearnerLanguagePreferences
{
    /**
     * @param  list<string>  $teaching
     * @param  list<string>  $understands
     * @param  list<string>  $learning
     */
    public function __construct(
        public array $teaching,
        public array $understands,
        public array $learning,
        public ?bool $bilingualOk,
    ) {}

    /** Every language the learner can be taught in, preferred first. @return list<string> */
    public function acceptable(): array
    {
        return array_values(array_unique(array_merge($this->teaching, $this->understands)));
    }
}
