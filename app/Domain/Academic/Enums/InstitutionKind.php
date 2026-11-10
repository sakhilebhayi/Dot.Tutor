<?php

namespace App\Domain\Academic\Enums;

enum InstitutionKind: string
{
    case University = 'university';
    case UniversityOfTechnology = 'university_of_technology';
    case TvetCollege = 'tvet_college';
    case PrivateHigherEducation = 'private_higher_education';
    case School = 'school';
    case Other = 'other';
}
