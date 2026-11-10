<?php

namespace App\Domain\Marketplace\Enums;

/**
 * Brief section 16. A level is assigned only by an operator after reviewing
 * evidence (VerificationService), never by the tutor. Each level names the
 * qualification kinds that can support it: an operator cannot assign a level
 * unless at least one VERIFIED qualification of a supporting kind exists.
 *
 * Public wording (label/description) is deliberately literal: a peer tutor is
 * described as a student who tutors, never as a qualified teacher.
 */
enum VerificationLevel: string
{
    case PeerStudentTutor = 'peer_student_tutor';
    case IndependentTutor = 'independent_tutor';
    case VerifiedQualifiedTutor = 'verified_qualified_tutor';
    case ProfessionalEducator = 'professional_educator';
    case InstitutionAffiliated = 'institution_affiliated';

    public function label(): string
    {
        return match ($this) {
            self::PeerStudentTutor => 'Peer tutor (student)',
            self::IndependentTutor => 'Experienced independent tutor',
            self::VerifiedQualifiedTutor => 'Verified qualified tutor',
            self::ProfessionalEducator => 'Professional educator',
            self::InstitutionAffiliated => 'Institution-affiliated educator',
        };
    }

    /** What Dot.Tutor checked, in plain words, so the label never claims more than the evidence. */
    public function description(): string
    {
        return match ($this) {
            self::PeerStudentTutor => 'A current student who tutors others. Dot.Tutor checked proof of enrolment. This is not a teaching qualification.',
            self::IndependentTutor => 'Dot.Tutor checked evidence of tutoring experience. No teaching qualification has been verified.',
            self::VerifiedQualifiedTutor => 'Dot.Tutor checked a degree, diploma or certificate in the subject area.',
            self::ProfessionalEducator => 'Dot.Tutor checked a teaching qualification or registration with a teaching council.',
            self::InstitutionAffiliated => 'Dot.Tutor checked a current appointment at a school, college or university.',
        };
    }

    /**
     * Qualification kinds that can support this level.
     *
     * @return list<QualificationKind>
     */
    public function supportingKinds(): array
    {
        return match ($this) {
            self::PeerStudentTutor => [QualificationKind::StudentEnrolment],
            self::IndependentTutor => [QualificationKind::TutoringExperience],
            self::VerifiedQualifiedTutor => [QualificationKind::Degree, QualificationKind::Diploma, QualificationKind::Certificate],
            self::ProfessionalEducator => [QualificationKind::TeachingQualification, QualificationKind::TeachingRegistration],
            self::InstitutionAffiliated => [QualificationKind::InstitutionAppointment],
        };
    }

    /** Matching term weight in [0, 1] (docs/R47_MATCHING.md). */
    public function matchingWeight(): float
    {
        return match ($this) {
            self::PeerStudentTutor => 0.4,
            self::IndependentTutor => 0.6,
            self::VerifiedQualifiedTutor => 0.8,
            self::ProfessionalEducator, self::InstitutionAffiliated => 1.0,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $l) => $l->value, self::cases());
    }
}
