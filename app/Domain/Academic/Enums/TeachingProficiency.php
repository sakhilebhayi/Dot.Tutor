<?php

namespace App\Domain\Academic\Enums;

/**
 * A tutor's self-declared level in a language they teach in. Self-declared
 * unless `verified_at` is set by an operator.
 */
enum TeachingProficiency: string
{
    case Working = 'working';
    case Fluent = 'fluent';
    case Native = 'native';

    public function rank(): int
    {
        return match ($this) {
            self::Working => 1,
            self::Fluent => 2,
            self::Native => 3,
        };
    }

    public function label(): string
    {
        return __('academic.proficiency.'.$this->value);
    }
}
