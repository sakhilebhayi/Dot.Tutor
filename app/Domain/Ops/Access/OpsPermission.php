<?php

namespace App\Domain\Ops\Access;

/**
 * Operations permissions (R-47 ops, docs/R47_UI_OPS.md section 2). A role is a set of these (OpsRole::permissions());
 * routes ask for one through RoutePermissions. Least privilege lives here: no role except admin holds both
 * safeguarding evidence and money, and finance never opens identity or screening documents.
 */
final class OpsPermission
{
    public const OVERVIEW_VIEW = 'overview.view';

    public const INSIGHTS_MONEY = 'insights.money';

    public const USERS_VIEW = 'users.view';

    public const USERS_RESTRICT = 'users.restrict';

    public const MINORS_MANAGE = 'minors.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const ACADEMIC_MANAGE = 'academic.manage';

    public const VERIFICATION_REVIEW = 'verification.review';

    public const SCREENING_MANAGE = 'screening.manage';

    public const SAFEGUARDING_VIEW = 'safeguarding.view';

    public const SAFEGUARDING_ACT = 'safeguarding.act';

    public const AUDIT_VIEW = 'audit.view';

    public const CONTENT_MODERATE = 'content.moderate';

    public const SUPPORT_VIEW = 'support.view';

    public const SUPPORT_ACT = 'support.act';

    public const BOOKINGS_VIEW = 'bookings.view';

    public const BOOKINGS_RESOLVE = 'bookings.resolve';

    public const FINANCE_VIEW = 'finance.view';

    public const FINANCE_REFUND = 'finance.refund';

    public const FINANCE_PAYOUTS = 'finance.payouts';

    public const FINANCE_MANAGE = 'finance.manage';

    /** Fallback for an operator route nobody has mapped: admin only (fail closed). */
    public const UNMAPPED = 'admin.only';

    /** @var array<string, string> permission => what it allows, in the words the team page shows */
    public const DESCRIPTIONS = [
        self::OVERVIEW_VIEW => 'See the overview of learners, tutors, bookings, content and satisfaction',
        self::INSIGHTS_MONEY => 'See revenue, commission and course sales on the overview',
        self::USERS_VIEW => 'Find people and open their account standing',
        self::USERS_RESTRICT => 'Warn, suspend and lift suspensions, with a reason',
        self::MINORS_MANAGE => 'Record a verified date of birth and remove a guardian link',
        self::ROLES_MANAGE => 'Grant and remove operator roles',
        self::ACADEMIC_MANAGE => 'Edit countries, curricula, levels, subjects, topics, institutions and languages',
        self::VERIFICATION_REVIEW => 'Open qualification evidence, verify or reject it, set verification levels, approve tutor profiles, verify teaching languages',
        self::SCREENING_MANAGE => 'Record and review tutor screening for work with minors, open screening evidence',
        self::SAFEGUARDING_VIEW => 'See safety reports, incidents, integrity cases and the AI safeguarding log',
        self::SAFEGUARDING_ACT => 'Act on reports, acknowledge, refer and close incidents, decide integrity cases',
        self::AUDIT_VIEW => 'Read the audit log and its chain check',
        self::CONTENT_MODERATE => 'Work held room posts, resource reviews, copyright complaints and held reviews',
        self::SUPPORT_VIEW => 'See support conversations',
        self::SUPPORT_ACT => 'Reply in support conversations',
        self::BOOKINGS_VIEW => 'See bookings and their timelines',
        self::BOOKINGS_RESOLVE => 'Resolve disputed bookings with an outcome and refund share',
        self::FINANCE_VIEW => 'See orders, payments, refunds, payouts, disputes and reconciliation',
        self::FINANCE_REFUND => 'Refund an order line through Paystack',
        self::FINANCE_PAYOUTS => 'Draft, export and settle payout batches',
        self::FINANCE_MANAGE => 'Resolve finance exceptions, record dispute outcomes, commission rules, platform coupons, credits and gifts',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::DESCRIPTIONS);
    }
}
