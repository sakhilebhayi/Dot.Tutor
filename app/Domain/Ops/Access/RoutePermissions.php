<?php

namespace App\Domain\Ops\Access;

use App\Domain\Ops\Access\OpsPermission as P;
use Illuminate\Support\Str;

/**
 * The permission each operator-only route needs (R-47 ops, adapted from Dot.Ehail R-46).
 *
 * Every operator route of every domain is listed here: the command centre's own `ops.*` routes, the pre-R-47
 * `/operator/*` desk, and the wave-1 and wave-2 domains' operator endpoints, whichever way the domain guarded them
 * (the `operator` middleware, a `can:` gate, or a check inside the controller). Two enforcement points read it:
 * EnsurePlatformOperator (routes behind the `operator` middleware) and OperatorRouteGuard (pushed onto the web
 * group, so routes guarded only by a gate or in the controller get the same role check). First matching pattern
 * wins. A route behind `operator` that matches nothing needs the admin role (fail closed), and
 * OperatorRouteMatrixTest fails when any operator route is unmapped, so a new route is never silently admin-only.
 */
final class RoutePermissions
{
    /** @var array<string, list<string>> route-name pattern => any of these permissions */
    public const MAP = [
        // Command centre (this agent).
        'ops.home' => [P::OVERVIEW_VIEW],
        'ops.users.team' => [P::ROLES_MANAGE],
        'ops.users.team.*' => [P::ROLES_MANAGE],
        'ops.users.restrict' => [P::USERS_RESTRICT],
        'ops.users.age' => [P::MINORS_MANAGE],
        'ops.users.guardian-links.revoke' => [P::MINORS_MANAGE],
        'ops.users' => [P::USERS_VIEW],
        'ops.users.show' => [P::USERS_VIEW],
        'ops.verification' => [P::VERIFICATION_REVIEW],
        'ops.verification.*' => [P::VERIFICATION_REVIEW],
        'ops.safeguarding.screening' => [P::SCREENING_MANAGE],
        'ops.safeguarding.screening.*' => [P::SCREENING_MANAGE],
        'ops.safeguarding.minors' => [P::SAFEGUARDING_VIEW, P::MINORS_MANAGE],
        'ops.safeguarding.reports.act' => [P::SAFEGUARDING_ACT],
        'ops.safeguarding.incidents.store' => [P::SAFEGUARDING_ACT],
        'ops.safeguarding.incidents.update' => [P::SAFEGUARDING_ACT],
        'ops.safeguarding.integrity.store' => [P::SAFEGUARDING_ACT],
        'ops.safeguarding.integrity.decide' => [P::SAFEGUARDING_ACT],
        'ops.safeguarding' => [P::SAFEGUARDING_VIEW],
        'ops.safeguarding.*' => [P::SAFEGUARDING_VIEW],
        'ops.audit' => [P::AUDIT_VIEW],
        'ops.moderation' => [P::CONTENT_MODERATE],
        'ops.moderation.*' => [P::CONTENT_MODERATE],
        'ops.reviews' => [P::CONTENT_MODERATE, P::BOOKINGS_VIEW],
        'ops.reviews.moderate' => [P::CONTENT_MODERATE],
        'ops.reviews.resolve' => [P::BOOKINGS_RESOLVE],
        'ops.reviews.bookings' => [P::BOOKINGS_VIEW],
        'ops.reviews.booking' => [P::BOOKINGS_VIEW],
        'ops.academic' => [P::ACADEMIC_MANAGE],
        'ops.academic.*' => [P::ACADEMIC_MANAGE],
        'ops.subjects' => [P::ACADEMIC_MANAGE],
        'ops.languages' => [P::ACADEMIC_MANAGE],
        'ops.payments.refund' => [P::FINANCE_REFUND],
        'ops.payments.release' => [P::FINANCE_MANAGE],
        'ops.payments.issues.resolve' => [P::FINANCE_MANAGE],
        'ops.payments.disputes.outcome' => [P::FINANCE_MANAGE],
        'ops.payments' => [P::FINANCE_VIEW],
        'ops.payments.*' => [P::FINANCE_VIEW],
        'ops.payouts' => [P::FINANCE_VIEW],
        'ops.payouts.*' => [P::FINANCE_PAYOUTS],
        'ops.support' => [P::SUPPORT_VIEW],
        'ops.support.*' => [P::SUPPORT_VIEW],

        // Pre-R-47 operator desk (routes/web.php).
        'operator.tutor-profiles.*' => [P::VERIFICATION_REVIEW],
        'operator.subjects.*' => [P::ACADEMIC_MANAGE],

        // Academic (docs/R47_ACADEMIC.md section 6). Teaching-language verification is a claim about a tutor.
        'operator.academic.teaching-languages.*' => [P::VERIFICATION_REVIEW],
        'operator.academic.*' => [P::ACADEMIC_MANAGE],

        // Marketplace (docs/R47_MARKETPLACE.md, API section).
        'marketplace.api.operator.bookings.resolve' => [P::BOOKINGS_RESOLVE],
        'marketplace.api.operator.reviews' => [P::CONTENT_MODERATE],
        'marketplace.api.operator.reviews.moderate' => [P::CONTENT_MODERATE],
        'marketplace.api.operator.*' => [P::VERIFICATION_REVIEW],
        'marketplace.operator.verification' => [P::VERIFICATION_REVIEW],
        'marketplace.operator.verification.decide' => [P::VERIFICATION_REVIEW],

        // Safety (docs/R47_SAFETY.md section 12).
        'safety.operator.audit' => [P::AUDIT_VIEW],
        'safety.operator.users.show' => [P::USERS_VIEW],
        'safety.operator.users.act' => [P::USERS_RESTRICT],
        'safety.operator.users.age' => [P::MINORS_MANAGE],
        'safety.operator.guardian-links.revoke' => [P::MINORS_MANAGE],
        'safety.operator.screening.*' => [P::SCREENING_MANAGE],
        'safety.operator.queue' => [P::SAFEGUARDING_VIEW],
        'safety.operator.reports.show' => [P::SAFEGUARDING_VIEW],
        'safety.operator.incidents.index' => [P::SAFEGUARDING_VIEW],
        'safety.operator.incidents.show' => [P::SAFEGUARDING_VIEW],
        'safety.operator.integrity.index' => [P::SAFEGUARDING_VIEW],
        'safety.operator.*' => [P::SAFEGUARDING_ACT],

        // Money (docs/R47_MONEY.md section 9).
        'money.api.ops.refunds.store' => [P::FINANCE_REFUND],
        'money.api.ops.batches.store' => [P::FINANCE_PAYOUTS],
        'money.api.ops.batches.export' => [P::FINANCE_PAYOUTS],
        'money.api.ops.payouts.*' => [P::FINANCE_PAYOUTS],
        'money.api.ops.lines.release' => [P::FINANCE_MANAGE],
        'money.api.ops.issues.resolve' => [P::FINANCE_MANAGE],
        'money.api.ops.credits.*' => [P::FINANCE_MANAGE],
        'money.api.ops.commission.store' => [P::FINANCE_MANAGE],
        'money.api.ops.coupons.store' => [P::FINANCE_MANAGE],
        'money.api.ops.disputes.store' => [P::FINANCE_MANAGE],
        'money.api.ops.disputes.outcome' => [P::FINANCE_MANAGE],
        'money.api.ops.entitlements.gift' => [P::FINANCE_MANAGE],
        'money.api.ops.*' => [P::FINANCE_VIEW],
        'money.ops' => [P::FINANCE_VIEW],

        // Learning library moderation (docs/R47_LEARNING.md): guarded in the controller and the policy.
        'learning.library.moderation' => [P::CONTENT_MODERATE],
        'learning.library.reviews.store' => [P::CONTENT_MODERATE],
        'learning.library.reports.decide' => [P::CONTENT_MODERATE],
        'api.learning.library.moderation' => [P::CONTENT_MODERATE],
        'api.learning.library.reviews.store' => [P::CONTENT_MODERATE],
        'api.learning.library.reports.decide' => [P::CONTENT_MODERATE],

        // Community held posts (docs/R47_COMMUNITY.md): gate community.moderate.
        'rooms.moderation' => [P::CONTENT_MODERATE],
        'rooms.moderation.decide' => [P::CONTENT_MODERATE],
        'community.api.moderation' => [P::CONTENT_MODERATE],
        'community.api.moderation.decide' => [P::CONTENT_MODERATE],

        // AI safeguarding log (docs/R47_AI.md): gate ai.review-log.
        'ai.operator.log' => [P::SAFEGUARDING_VIEW],
        'api.ai.operator.log' => [P::SAFEGUARDING_VIEW],
    ];

    /**
     * Gates other domains define for "any platform operator". For a person who holds roles, the gate answers from
     * the role (Gate::before in TutorOpsServiceProvider); a legacy full-access operator keeps the domain's own rule.
     *
     * @var array<string, list<string>>
     */
    public const GATES = [
        'safety.operate' => [P::SAFEGUARDING_VIEW, P::SCREENING_MANAGE, P::MINORS_MANAGE, P::USERS_VIEW, P::AUDIT_VIEW],
        'money.finance' => [P::FINANCE_VIEW],
        'community.moderate' => [P::CONTENT_MODERATE],
        'ai.review-log' => [P::SAFEGUARDING_VIEW],
    ];

    /**
     * Routes any signed-in person uses for their own things, which an operator may also open when it is not theirs
     * (the conversation policy lets operators read support threads and reported conversations). An operator who is
     * not a participant needs one of these permissions (OperatorRouteGuard narrows; it never widens).
     *
     * @var array<string, list<string>>
     */
    public const NON_PARTY = [
        'safety.conversations.show' => [P::SUPPORT_VIEW, P::SAFEGUARDING_VIEW],
        'safety.conversations.messages.store' => [P::SUPPORT_ACT, P::SAFEGUARDING_ACT],
        'safety.attachments.show' => [P::SUPPORT_VIEW, P::SAFEGUARDING_VIEW],
    ];

    /**
     * Permissions (any of) the route needs.
     *
     * @return list<string>
     */
    public static function for(?string $routeName): array
    {
        return self::match($routeName) ?? [P::UNMAPPED];
    }

    /** @return list<string>|null null when no pattern matches */
    public static function match(?string $routeName): ?array
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        foreach (self::MAP as $pattern => $permissions) {
            if ($pattern === $routeName || (str_contains($pattern, '*') && Str::is($pattern, $routeName))) {
                return $permissions;
            }
        }

        return null;
    }

    public static function isMapped(?string $routeName): bool
    {
        return self::match($routeName) !== null;
    }

    /**
     * The roles whose permissions reach a route (for the role matrix in the docs and on the team page).
     *
     * @return list<string>
     */
    public static function rolesFor(string $routeName): array
    {
        $needs = self::for($routeName);

        return collect(OpsRole::cases())
            ->filter(fn (OpsRole $role) => array_intersect($needs, $role->permissions()) !== [] || $role === OpsRole::Admin)
            ->map(fn (OpsRole $role) => $role->value)
            ->values()
            ->all();
    }
}
