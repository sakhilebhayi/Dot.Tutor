<?php

namespace App\Domain\Ops\Access;

use App\Domain\Ops\Access\OpsPermission as P;

/**
 * Operator roles (architecture section 2, brief section 20). Each is a fixed set of permissions; a person may hold
 * several. Roles sit on top of users.is_platform_operator (OpsAccess).
 */
enum OpsRole: string
{
    case Admin = 'admin';
    case AcademicEditor = 'academic_editor';
    case VerificationReviewer = 'verification_reviewer';
    case SafeguardingOfficer = 'safeguarding_officer';
    case ContentModerator = 'content_moderator';
    case SupportAgent = 'support_agent';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::AcademicEditor => 'Academic editor',
            self::VerificationReviewer => 'Verification reviewer',
            self::SafeguardingOfficer => 'Safeguarding officer',
            self::ContentModerator => 'Content moderator',
            self::SupportAgent => 'Support agent',
            self::Finance => 'Finance',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Admin => 'Everything, including granting roles.',
            self::AcademicEditor => 'Curricula, levels, subjects, topics, institutions and languages. No people, no money.',
            self::VerificationReviewer => 'Qualification evidence, verification levels, tutor profiles and teaching languages. No money, no safeguarding cases.',
            self::SafeguardingOfficer => 'Reports, incidents, minors\' accounts, screening for work with minors, restrictions and the audit log. No money.',
            self::ContentModerator => 'Held room posts, resource reviews and quality tiers, copyright complaints, held tutor reviews.',
            self::SupportAgent => 'Support conversations, bookings and booking disputes. No refunds.',
            self::Finance => 'Orders, payments, commission, refunds, payouts, disputes and unmatched payments. No identity or screening documents.',
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => P::all(),
            self::AcademicEditor => [P::OVERVIEW_VIEW, P::ACADEMIC_MANAGE],
            self::VerificationReviewer => [P::OVERVIEW_VIEW, P::USERS_VIEW, P::VERIFICATION_REVIEW],
            self::SafeguardingOfficer => [P::OVERVIEW_VIEW, P::USERS_VIEW, P::USERS_RESTRICT, P::MINORS_MANAGE, P::SCREENING_MANAGE,
                P::SAFEGUARDING_VIEW, P::SAFEGUARDING_ACT, P::AUDIT_VIEW, P::SUPPORT_VIEW],
            self::ContentModerator => [P::OVERVIEW_VIEW, P::USERS_VIEW, P::CONTENT_MODERATE],
            self::SupportAgent => [P::OVERVIEW_VIEW, P::USERS_VIEW, P::SUPPORT_VIEW, P::SUPPORT_ACT, P::BOOKINGS_VIEW, P::BOOKINGS_RESOLVE],
            self::Finance => [P::OVERVIEW_VIEW, P::INSIGHTS_MONEY, P::USERS_VIEW, P::BOOKINGS_VIEW, P::BOOKINGS_RESOLVE,
                P::FINANCE_VIEW, P::FINANCE_REFUND, P::FINANCE_PAYOUTS, P::FINANCE_MANAGE],
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
