<?php

namespace App\Domain\Safety\Enums;

/** Academic integrity policy areas (brief section 15). The policy text lives in docs/R47_SAFETY.md section 9. */
enum IntegrityKind: string
{
    case Plagiarism = 'plagiarism';
    case ExamMisconduct = 'exam_misconduct';
    case CheatingRequest = 'cheating_request';
    case Impersonation = 'impersonation';
    case FakeReview = 'fake_review';
    case MisleadingQualification = 'misleading_qualification';
    case Copyright = 'copyright';
    case FraudulentSale = 'fraudulent_sale';
    case MisrepresentedResource = 'misrepresented_resource';

    public function label(): string
    {
        return match ($this) {
            self::Plagiarism => 'Plagiarism',
            self::ExamMisconduct => 'Examination misconduct',
            self::CheatingRequest => 'Request to complete assessed work',
            self::Impersonation => 'Impersonation',
            self::FakeReview => 'Fake or incentivised review',
            self::MisleadingQualification => 'Misleading tutor qualification',
            self::Copyright => 'Copyright infringement',
            self::FraudulentSale => 'Fraudulent course or resource sale',
            self::MisrepresentedResource => 'Misrepresented educational resource',
        };
    }
}
