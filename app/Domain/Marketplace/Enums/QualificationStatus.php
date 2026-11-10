<?php

namespace App\Domain\Marketplace\Enums;

/**
 * unverified: declared by the tutor, no evidence sent.
 * submitted: evidence attached and sent for review.
 * verified / rejected: an operator reviewed the evidence (with an audit record).
 */
enum QualificationStatus: string
{
    case Unverified = 'unverified';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';

    /** The public wording. Only "verified" claims that Dot.Tutor checked anything. */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Verified => 'Verified by Dot.Tutor',
            self::Submitted => 'Self-declared, evidence under review',
            self::Unverified => 'Self-declared, not verified',
            self::Rejected => 'Not verified',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
