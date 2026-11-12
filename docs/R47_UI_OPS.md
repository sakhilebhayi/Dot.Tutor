# R-47 operations command centre and guardian views (ops agent, D-1300 onward)

Brief sections 20 (admin and educational operations command centre), 15 (safeguarding operations, guardian involvement), 16 (verification review), 9 (resource moderation, copyright complaints), 17 (refunds, payouts, disputes) and the safety journey of 25. Code: `app/Domain/Ops/**` (provider `TutorOpsServiceProvider`), `routes/tutor/ops.php`, views `resources/views/ops/**` and `resources/views/guardian/**`, styles `resources/css/ops.css` (one import line in `app.css`), migration `2026_11_12_130000_create_ops_role_assignments_table`, seeder `DemoOpsSeeder` (local only), tests `tests/Feature/Ops/**` (25 tests). Screenshots: `docs/R47_screens/ops/`.

## 1. What is built

| Area | State | Proof |
|---|---|---|
| Operator roles on top of `users.is_platform_operator` (admin, academic_editor, verification_reviewer, safeguarding_officer, content_moderator, support_agent, finance) | Built | `TeamManagementTest`, `OperatorRouteMatrixTest` |
| Route permission matrix for every operator route of every domain, enforced before route-model binding | Built | `OperatorRouteMatrixTest` (scan of the whole router, fails on an unclassified or stale entry) |
| Team management screen, `php artisan ops:grant` for the first administrator | Built | `TeamManagementTest` |
| Overview (queues, people, lessons, weekly requests chart, money for finance, languages, geography, satisfaction, retention cohorts, learning) from real rows | Built | `CommandCentreScreensTest`, browser check |
| Users (filters for role, minors, suspended, warned), account page with restrictions history, warn/suspend/lift, verified date of birth, guardian link removal | Built | `CommandCentreScreensTest` |
| Verification queue (evidence, applications, teaching languages, tutors), tutor verification record (levels, decisions, screening status) | Built | `CommandCentreScreensTest` |
| Safeguarding: reports by priority with the child-safety fast path, report evidence (unredacted original for staff), actions, incidents with escalation timers, integrity cases, minors and the rules in force, screening (four eyes), AI log tab | Built | `CommandCentreScreensTest` |
| Audit history viewer with the hash-chain check on request | Built | `CommandCentreScreensTest` |
| Content: held room posts, resources to review (source check, quality review), complaints and takedowns, quality tiers, published courses | Built | `CommandCentreScreensTest` |
| Reviews and disputes: disputed lessons (resolve with refund share), held tutor reviews, all bookings with timelines | Built | `CommandCentreScreensTest` |
| Money: orders and payments, refunds (finance only, one-time idempotency key per form), exceptions and unmatched payments, card disputes, commission rules, payouts and batches (bank file link, mark paid/failed) | Built | `CommandCentreScreensTest`, `OperatorRouteMatrixTest` |
| Support conversations (read audited, replies through safety's Messenger) | Built | `CommandCentreScreensTest` |
| Academic structure in the shell (academic agent's CRUD at `/ops/academic`, restyled through view overrides) | Built | `CommandCentreScreensTest`, existing `OperatorAcademicTest` unchanged |
| Guardian: home (what waits, children), child page (decisions with reasons, lessons, purchases incl. money orders, messages access, reports and safety notices, settings), invitation for a minor without a guardian, restyled acceptance and `/safety/guardian` pages | Built | `GuardianViewTest`, browser check at 375/390 |

Not built, on purpose: creating commission rules, platform coupons and credits from a form (the finance API exists; the screen lists rules and says so); payout transfer through Paystack from the screen (switched off by default; the API route is classified); a course review queue (no course review process exists in learning; the content screen says so); support thread closing (safety has no close action).

## 2. Roles and the route permission matrix

Roles sit on `users.is_platform_operator`: someone who is not an operator holds nothing whatever rows exist. An operator with no role rows keeps the full access every operator had before roles (legacy full access, D-1301); the team page lists such people with a warning. `OpsAccess` answers, `RoutePermissions::MAP` classifies routes (first match wins; unmapped `operator` routes are admin only), `RoleAdministration` changes roles (audited with before and after, last administrator protected, nobody removes themselves).

Enforcement points:
1. `EnsurePlatformOperator` (the `operator` alias) now checks the route's permission.
2. `OperatorRouteGuard`, inserted into the web group before `SubstituteBindings`, enforces the map for routes guarded only by a `can:` gate or inside a controller (money finance, community held posts, learning library moderation, the AI log), and narrows shared routes an operator may open on someone else's behalf (`NON_PARTY`: support or safeguarding permission to read a conversation one is not in).
3. `Gate::before` answers the domains' "any operator" gates (`safety.operate`, `money.finance`, `community.moderate`, `ai.review-log`) from the role for role holders; legacy operators and everyone else fall through to the domain's rule. For role holders this replaces the interim `MONEY_FINANCE_OPERATOR_EMAILS` narrowing (D-1303).
4. The operations rail hides items the role cannot open (`OpsShellNavigation`, bound in place of the ds `ShellNavigation`; other shells untouched).

Least privilege: finance never opens identity, screening or safeguarding evidence; safeguarding never moves money; support resolves disputes but cannot refund (a refund due becomes a finance exception); moderators see no money; the academic editor sees no people.

| Permission | Adm | Acad | Verif | Safe | Cont | Supp | Fin |
|---|---|---|---|---|---|---|---|
| `overview.view` See the overview of learners, tutors, bookings, content and satisfaction | yes | yes | yes | yes | yes | yes | yes |
| `insights.money` See revenue, commission and course sales on the overview | yes | | | | | | yes |
| `users.view` Find people and open their account standing | yes | | yes | yes | yes | yes | yes |
| `users.restrict` Warn, suspend and lift suspensions, with a reason | yes | | | yes | | | |
| `minors.manage` Record a verified date of birth and remove a guardian link | yes | | | yes | | | |
| `roles.manage` Grant and remove operator roles | yes | | | | | | |
| `academic.manage` Edit countries, curricula, levels, subjects, topics, institutions and languages | yes | yes | | | | | |
| `verification.review` Open qualification evidence, verify or reject it, set verification levels, approve tutor profiles, verify teaching languages | yes | | yes | | | | |
| `screening.manage` Record and review tutor screening for work with minors, open screening evidence | yes | | | yes | | | |
| `safeguarding.view` See safety reports, incidents, integrity cases and the AI safeguarding log | yes | | | yes | | | |
| `safeguarding.act` Act on reports, acknowledge, refer and close incidents, decide integrity cases | yes | | | yes | | | |
| `audit.view` Read the audit log and its chain check | yes | | | yes | | | |
| `content.moderate` Work held room posts, resource reviews, copyright complaints and held reviews | yes | | | | yes | | |
| `support.view` See support conversations | yes | | | yes | | yes | |
| `support.act` Reply in support conversations | yes | | | | | yes | |
| `bookings.view` See bookings and their timelines | yes | | | | | yes | yes |
| `bookings.resolve` Resolve disputed bookings with an outcome and refund share | yes | | | | | yes | yes |
| `finance.view` See orders, payments, refunds, payouts, disputes and reconciliation | yes | | | | | | yes |
| `finance.refund` Refund an order line through Paystack | yes | | | | | | yes |
| `finance.payouts` Draft, export and settle payout batches | yes | | | | | | yes |
| `finance.manage` Resolve finance exceptions, record dispute outcomes, commission rules, platform coupons, credits and gifts | yes | | | | | | yes |

| Route pattern | Needs (any of) |
|---|---|
| `ops.home` | `overview.view` |
| `ops.users.team` | `roles.manage` |
| `ops.users.team.*` | `roles.manage` |
| `ops.users.restrict` | `users.restrict` |
| `ops.users.age` | `minors.manage` |
| `ops.users.guardian-links.revoke` | `minors.manage` |
| `ops.users` | `users.view` |
| `ops.users.show` | `users.view` |
| `ops.verification` | `verification.review` |
| `ops.verification.*` | `verification.review` |
| `ops.safeguarding.screening` | `screening.manage` |
| `ops.safeguarding.screening.*` | `screening.manage` |
| `ops.safeguarding.minors` | `safeguarding.view`, `minors.manage` |
| `ops.safeguarding.reports.act` | `safeguarding.act` |
| `ops.safeguarding.incidents.store` | `safeguarding.act` |
| `ops.safeguarding.incidents.update` | `safeguarding.act` |
| `ops.safeguarding.integrity.store` | `safeguarding.act` |
| `ops.safeguarding.integrity.decide` | `safeguarding.act` |
| `ops.safeguarding` | `safeguarding.view` |
| `ops.safeguarding.*` | `safeguarding.view` |
| `ops.audit` | `audit.view` |
| `ops.moderation` | `content.moderate` |
| `ops.moderation.*` | `content.moderate` |
| `ops.reviews` | `content.moderate`, `bookings.view` |
| `ops.reviews.moderate` | `content.moderate` |
| `ops.reviews.resolve` | `bookings.resolve` |
| `ops.reviews.bookings` | `bookings.view` |
| `ops.reviews.booking` | `bookings.view` |
| `ops.academic` | `academic.manage` |
| `ops.subjects` | `academic.manage` |
| `ops.languages` | `academic.manage` |
| `ops.payments.refund` | `finance.refund` |
| `ops.payments.release` | `finance.manage` |
| `ops.payments.issues.resolve` | `finance.manage` |
| `ops.payments.disputes.outcome` | `finance.manage` |
| `ops.payments` | `finance.view` |
| `ops.payments.*` | `finance.view` |
| `ops.payouts` | `finance.view` |
| `ops.payouts.batch` | `finance.view` |
| `ops.payouts.*` | `finance.payouts` |
| `ops.support` | `support.view` |
| `ops.support.reply` | `support.act` |
| `ops.support.*` | `support.view` |
| `operator.tutor-profiles.*` | `verification.review` |
| `operator.subjects.*` | `academic.manage` |
| `operator.academic.teaching-languages.*` | `verification.review` |
| `operator.academic.*` | `academic.manage` |
| `marketplace.api.operator.bookings.resolve` | `bookings.resolve` |
| `marketplace.api.operator.reviews` | `content.moderate` |
| `marketplace.api.operator.reviews.moderate` | `content.moderate` |
| `marketplace.api.operator.*` | `verification.review` |
| `marketplace.operator.verification` | `verification.review` |
| `marketplace.operator.verification.decide` | `verification.review` |
| `safety.operator.audit` | `audit.view` |
| `safety.operator.users.show` | `users.view` |
| `safety.operator.users.act` | `users.restrict` |
| `safety.operator.users.age` | `minors.manage` |
| `safety.operator.guardian-links.revoke` | `minors.manage` |
| `safety.operator.screening.*` | `screening.manage` |
| `safety.operator.queue` | `safeguarding.view` |
| `safety.operator.reports.show` | `safeguarding.view` |
| `safety.operator.incidents.index` | `safeguarding.view` |
| `safety.operator.incidents.show` | `safeguarding.view` |
| `safety.operator.integrity.index` | `safeguarding.view` |
| `safety.operator.*` | `safeguarding.act` |
| `money.api.ops.refunds.store` | `finance.refund` |
| `money.api.ops.batches.store` | `finance.payouts` |
| `money.api.ops.batches.export` | `finance.payouts` |
| `money.api.ops.payouts.*` | `finance.payouts` |
| `money.api.ops.lines.release` | `finance.manage` |
| `money.api.ops.issues.resolve` | `finance.manage` |
| `money.api.ops.credits.*` | `finance.manage` |
| `money.api.ops.commission.store` | `finance.manage` |
| `money.api.ops.coupons.store` | `finance.manage` |
| `money.api.ops.disputes.store` | `finance.manage` |
| `money.api.ops.disputes.outcome` | `finance.manage` |
| `money.api.ops.entitlements.gift` | `finance.manage` |
| `money.api.ops.*` | `finance.view` |
| `money.ops` | `finance.view` |
| `learning.library.moderation` | `content.moderate` |
| `learning.library.reviews.store` | `content.moderate` |
| `learning.library.reports.decide` | `content.moderate` |
| `api.learning.library.moderation` | `content.moderate` |
| `api.learning.library.reviews.store` | `content.moderate` |
| `api.learning.library.reports.decide` | `content.moderate` |
| `rooms.moderation` | `content.moderate` |
| `rooms.moderation.decide` | `content.moderate` |
| `community.api.moderation` | `content.moderate` |
| `community.api.moderation.decide` | `content.moderate` |
| `ai.operator.log` | `safeguarding.view` |
| `api.ai.operator.log` | `safeguarding.view` |

## 3. Screens

All operator screens use `<x-layouts::operations>` (dense step) and the `x-td` kit; tabs only show what the role can open; every list has empty, real-data and error states (validation errors and refusals from the domain services come back as toasts or field errors). Overview question: "what needs a person now, and is learning happening?". The queue list is first and laid out in three columns at desk width so the figures below are on the first screen.

Charts (dataviz method): one series, one hue (primary) on the data with the current week in the full hue and earlier weeks a lighter step; recessive grid; value labels only on the current and the highest week; native hover titles; a "Show as a table" view; bar lists print every figure in ink; the retention heatmap is one hue in four steps capped at 46% so the printed figure keeps its contrast in both themes; missing months print a dash, future months "not yet".

Metric definitions (all over the last 30 days unless said): active learners = people with a non-cancelled lesson starting in the window or a course enrolment touched in it; active tutors = approved tutors with a confirmed, started or completed lesson in it; completion = held / (held + learner did not come) for lessons whose start has passed; weekly requests = `tutor_sessions` created per Monday-start week in `tutor.timezone`; commission = net credits to the ledger's `platform_commission` account; tutor earnings = net of earnings created; retention = learners grouped by the month of their first held or booked lesson and the share with another lesson one, two and three months later; satisfaction = published lesson reviews and published course reviews only. Library downloads are not recorded anywhere, and the screen says so instead of showing a number.

Guardian screens use `<x-layouts::learner>` (standard step, phone first): one decision per block with thumb-sized Approve and Decline (56px at the large size) and an optional reason the child is told; decisions, settings, recording consent and link removal all post to the safety domain's own guardian endpoints, which check the link server-side. A guardian can only open their own linked children (404 otherwise).

## 4. Contract notes for other owners

- **All owners**: a new operator route must be added to `App\Domain\Ops\Access\RoutePermissions::MAP` (the matrix test fails otherwise). Prefer naming operator routes `*.operator.*` or `*.ops.*`.
- **ds**: `resources/css/ops.css` sets `.table-scroll { position: relative }` so a visually hidden header label cannot escape the scroll region and widen a phone's layout (found on 390 px). Consider moving it into `components.css`. The ds navigation test now expects "Guardian" in the learner rail and the `ops.*` routes in the operations rail (updated, D-1310). `ShellNavigation` is subclassed, not edited.
- **money**: `money.finance` answers from the finance role for role holders; the email list still applies to legacy operators.
- **safety**: `safety::guardian-dashboard` and `safety::guardian-accept` are overridden by `resources/views/guardian/overrides/safety` (same data and form fields; the JSON answers are unchanged). The operator account view and age record logic used by `/ops/users/{id}/age` mirrors `AccountsController::age` (no service exists for it).
- **academic**: `academic::operator.*` views are overridden by `resources/views/ops/overrides/academic` (same data, same forms, same text the tests read).
- **pre-R-47 code**: `App\Http\Middleware\EnsurePlatformOperator` consults the matrix.

## 5. Owner items

- Grant the first administrator after deploy: `php artisan ops:grant owner@example.com` (the person must have signed in through InfoDot once), then give every other operator a role on the team page; until then existing operators keep full access.
- Decide who holds finance and who holds safeguarding; with four eyes on, screening needs two safeguarding officers.
- The academic reference names still need an academic editor's review (academic owner item).

## 6. Checks run (2026-11-12, after merging feat/r47-tutor with student and deploy)

- `php artisan test --compact`: 893 tests, 886 pass, 7 skipped, 0 fail (25 new in `tests/Feature/Ops`; `DesignSystemKitTest` updated, D-1310).
- `route:cache` and `view:cache` pass; Pint clean; `npm run build` clean.
- `impeccable detect`: 0 findings. Taste `lint --app` and `inspect --app`: see `docs/R47_SKILLS.md`.
- Browser: see `docs/R47_SKILLS.md` and `docs/R47_screens/ops/`.
