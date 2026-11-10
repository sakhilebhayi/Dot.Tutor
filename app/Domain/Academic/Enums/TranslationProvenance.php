<?php

namespace App\Domain\Academic\Enums;

/**
 * Who produced a translated version: the content's own author, a human
 * translator, or a machine (which must name its model).
 */
enum TranslationProvenance: string
{
    case Author = 'author';
    case Human = 'human';
    case Machine = 'machine';

    public function label(): string
    {
        return __('academic.provenance.'.$this->value);
    }
}
