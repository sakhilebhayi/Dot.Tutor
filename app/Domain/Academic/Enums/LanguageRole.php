<?php

namespace App\Domain\Academic\Enums;

/**
 * The roles a person holds a language in. Matching reads PrefersTeaching,
 * Understands and CanTeachIn; Interface only chooses UI strings.
 */
enum LanguageRole: string
{
    case Interface = 'interface';
    case Understands = 'understands';
    case PrefersTeaching = 'prefers_teaching';
    case Learning = 'learning';
    case CanTeachIn = 'can_teach_in';

    public function label(): string
    {
        return __('academic.language_roles.'.$this->value);
    }
}
