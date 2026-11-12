<?php

namespace App\Domain\Safety\Enums;

/**
 * Screening checks an operator records by hand, with evidence. None of these is queried automatically: neither
 * South African register offers a public lookup (docs/R47_RESEARCH.md, safeguarding section).
 */
enum ScreeningKind: string
{
    // Children's Act 38 of 2005, Part B of the National Child Protection Register (Form 29 enquiry to DSD).
    case ChildProtectionRegister = 'child_protection_register';
    // Criminal Law (Sexual Offences) Amendment Act 32 of 2007, National Register for Sex Offenders (Form 8).
    case SexOffendersRegister = 'sex_offenders_register';
    case PoliceClearance = 'police_clearance';
    case IdentityDocument = 'identity_document';
    case ProfessionalRegistration = 'professional_registration';
    case Reference = 'reference';

    public function label(): string
    {
        return match ($this) {
            self::ChildProtectionRegister => 'National Child Protection Register, Part B (manual enquiry)',
            self::SexOffendersRegister => 'National Register for Sex Offenders (manual enquiry)',
            self::PoliceClearance => 'Police clearance certificate',
            self::IdentityDocument => 'Identity document seen',
            self::ProfessionalRegistration => 'Professional registration (for example SACE)',
            self::Reference => 'Reference check',
        };
    }
}
