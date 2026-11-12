<?php

namespace App\Domain\Marketplace\Enums;

enum QualificationKind: string
{
    case StudentEnrolment = 'student_enrolment';
    case TutoringExperience = 'tutoring_experience';
    case Degree = 'degree';
    case Diploma = 'diploma';
    case Certificate = 'certificate';
    case TeachingQualification = 'teaching_qualification';
    case TeachingRegistration = 'teaching_registration';
    case InstitutionAppointment = 'institution_appointment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::StudentEnrolment => 'Current student enrolment',
            self::TutoringExperience => 'Tutoring experience',
            self::Degree => 'Degree',
            self::Diploma => 'Diploma',
            self::Certificate => 'Certificate',
            self::TeachingQualification => 'Teaching qualification',
            self::TeachingRegistration => 'Teaching council registration',
            self::InstitutionAppointment => 'Institution appointment',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
