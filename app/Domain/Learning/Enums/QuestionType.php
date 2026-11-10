<?php

namespace App\Domain\Learning\Enums;

/** Question formats marked on the server. */
enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultiSelect = 'multi_select';
    case Numeric = 'numeric';
    case ShortText = 'short_text';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
