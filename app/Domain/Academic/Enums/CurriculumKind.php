<?php

namespace App\Domain\Academic\Enums;

enum CurriculumKind: string
{
    case School = 'school';
    case Tertiary = 'tertiary';
    case Professional = 'professional';
}
