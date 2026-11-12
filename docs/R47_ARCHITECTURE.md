# R-47 architecture contract (binding for every build agent)

The brief is `docs/R47_BRIEF.md`; the audit `docs/R47_AUDIT.md`. This file fixes ownership, shared conventions and the seams between domains so parallel agents can build without colliding. Change it only by recording a decision in `docs/R47_DECISIONS.md` and telling the coordinator in your report.

## 0. Product shape in one paragraph

Dot.Tutor is one place where a learner says what they need in plain words ("Grade 11 Maths, in isiZulu, after school, under R250") and gets explained tutor matches, a room of classmates at their level and subject, courses and study material, and a way to see progress. A tutor builds a business there: a verified profile, offerings (lessons, groups, packages, courses, materials, rooms), bookings, students, earnings and payouts. A guardian oversees a minor. Operators run academic structure, quality, safeguarding and money. Language is a first-class matching dimension, not a UI label.

## 1. Ownership (paths are owned; touch others only via a seam or a one-line addition)

| Agent | Owns | Decision base | Port |
|---|---|---|---|
| ds | `DESIGN.md`, `resources/css/ds/**`, `resources/js/ds/**`, `resources/views/components/td/**`, `resources/views/layouts/**` (new shells), `resources/views/welcome.blade.php`, public pages, self-hosted fonts in `public/fonts`, `docs/R47_DS.md` | D-100 | 8700 |
| academic | `app/Domain/Academic/**` (countries, curricula, levels, subjects, topics, institutions, languages, learner profiles, locale + translation provenance), `routes/tutor/academic.php`, `lang/**`, `docs/R47_ACADEMIC.md`, `docs/R47_I18N.md` | D-200 | 8701 |
| marketplace | `app/Domain/Marketplace/**` (tutor profile v2, qualifications, verification, teaching scope, offerings, matching, booking v2, reviews), `routes/tutor/marketplace.php`, existing `app/Services/Scheduling/**`, `app/Models/Tutor*`, `docs/R47_MARKETPLACE.md`, `docs/R47_MATCHING.md` | D-300 | 8702 |
| safety | `app/Domain/Safety/**` (age bands, guardians, consent and approvals, messaging with restrictions, reports, moderation queue, incidents, audit log), `routes/tutor/safety.php`, `docs/R47_SAFETY.md` | D-400 | 8703 |
| money | `app/Domain/Money/**` (Money value object, orders, Paystack payments, webhooks, ledger, commissions, earnings, payouts, refunds, coupons, credits, receipts), `routes/tutor/money.php`, `config/money.php`, `docs/R47_MONEY.md` | D-500 | 8704 |
| learning | `app/Domain/Learning/**` (courses, sections, lessons, media, enrolment, protected delivery, progress, certificates, course reviews, resource library, assessments and question banks, topic mastery), `routes/tutor/learning.php`, `docs/R47_LEARNING.md` | D-600 | 8705 |

Wave 2 (after wave 1 merges): community (rooms, Q&A, study groups, tutor rooms, D-700), classroom (live lessons, D-800), ai (assistant, translation assist, D-900), comms (notifications, D-1000), student UI (D-1100), tutor UI (D-1200), ops UI and guardian UI (D-1300). Wave 3: integ (D-1400), security (D-1500), perf (D-1600), a11y (D-1700), qa (D-1800), deploy (D-1900). Polish D-2000 and up.

Shared files take **one-line additions only**: `routes/web.php` (one `require` per owner), `bootstrap/providers.php` (one `Tutor<Name>ServiceProvider` per owner, registered in that order; an integration provider is added last in wave 2), `config/*.php` new files only.

## 2. Conventions

- Laravel 13, PHP 8.4. Follow the Boost guidelines in `CLAUDE.md` (sibling conventions, Pint, feature tests). Domain code lives in `app/Domain/<Name>/{Models,Services,Http/Controllers,Policies,Events,Jobs,Console}`; each domain registers its own provider, policies, rate limiters, scheduled commands and event listeners in its provider (never in route files).
- **Money** is integer minor units with an ISO currency (`amount_minor` + `currency`), never floats. `App\Domain\Money\Money` is the value object (money agent). Until it lands, other domains store `*_minor` integers and `currency` strings. Existing decimal columns (`tutor_profiles.hourly_rate`, `tutor_sessions.rate`) gain `*_minor` companions, backfilled; decimals stay for history.
- **Identity:** InfoDot is the only sign-in. Local registration stays closed in production (tests and the E2E harness may create users directly). Roles are capabilities on the user, not separate accounts: learner, tutor, guardian, operator roles. One person can be a learner and a tutor.
- **Age:** `App\Domain\Safety\AgeBand` (child under 13, teen 13 to 17, adult 18+) from a date of birth on the learner profile. Every feature that lets people talk, meet, pay or publish asks the safety seam `SafeguardingGate` before acting; the check is server-side (policies and services), never only in the UI.
- **Academic scope** is data, not code: a curriculum belongs to a country and a qualification framework; levels are ordered within a curriculum and grouped into stages (`foundation`, `intermediate`, `senior`, `fet` for school; `undergraduate`, `honours`, `pg_diploma`, `masters`, `doctoral`, `professional` for tertiary; stages are seeded data, not an enum). Subjects are a global catalogue; a `curriculum_subject_levels` table says which subject is offered at which level of which curriculum. South Africa CAPS and IEB (Grades 1 to 12) and a tertiary track are seeded; Cambridge is seeded as a second example. Nothing is hardcoded to one country.
- **Languages** are a table (BCP 47 code, English name, own-language name, script, `is_official_za`), seeded with the eleven official South African written languages plus Portuguese, French and Swahili. People hold languages with roles: `interface`, `understands`, `prefers_teaching`, `learning`; a tutor holds `can_teach_in` with a self-declared level and an optional verified flag. Matching uses teaching languages, never the interface locale.
- **Translations:** original content keeps its `original_locale`. `App\Domain\Academic\Translations` stores translated fields with provenance (`author`, `human`, `machine`), translator, model when machine, and review status. UI shows the original and the translation side by side on request and labels machine translation as such.
- **Files:** private disks only (`storage/app/private/<domain>`), served by an authorising controller that streams with `nosniff` and `Content-Disposition`; content sniffing, size caps, image re-encode to strip metadata; no virus scanner on the host (say so).
- **Errors:** JSON endpoints answer `{error, message}` with proper status codes. Web forms redirect with validation errors.
- **Idempotency:** any POST that creates money movement or a booking accepts an `Idempotency-Key` header and is unique-guarded in the database.
- **Audit:** `App\Domain\Safety\Audit::record($actor, $action, $subject, $context)` (safety agent) for every moderation, safeguarding, verification, money and admin action. Until it lands, call a no-op seam bound with `bindIf`.
- **Seams:** cross-domain calls go through interfaces bound with `bindIf` defaults in the owning provider (honest null implementations). The wave-2 integration agent binds real implementations in `TutorIntegrationServiceProvider`, registered last.
- **Operators:** `users.is_platform_operator` stays; operator roles (admin, academic_editor, verification_reviewer, safeguarding_officer, content_moderator, support_agent, finance) are added by the ops agent with a route permission matrix; until then operator routes use the existing `operator` middleware.
- **Honesty:** no fake tutors, reviews, metrics, counts, learners or AI output in UI or production seeders. Demo seeders are `Demo*`, refuse production, and exist for local checks and the E2E harness only.

## 3. Seams (interfaces each owner provides with a null default)

| Interface | Owner | Used by |
|---|---|---|
| `Academic\Contracts\AcademicScope` (resolve a learner's country, curriculum, level, subjects; validate a tutor's teaching scope) | academic | marketplace, community, learning |
| `Academic\Contracts\LanguageProfile` (a user's languages by role) | academic | marketplace (matching), community, ai |
| `Safety\Contracts\SafeguardingGate` (`allows($actor, $ability, $subject): Decision` with reasons: book, pay, message, join_room, meet_live, publish, view_contact) | safety | everyone |
| `Safety\Contracts\GuardianApprovals` (request and check guardian approval for a booking, purchase or room) | safety | marketplace, money, learning, community |
| `Safety\Contracts\ReportSink` (report any reportable model) | safety | community, learning, marketplace |
| `Money\Contracts\Checkout` (create an order for a purchasable, start payment, return status) | money | marketplace, learning, community |
| `Money\Contracts\Purchasable` (implemented by bookings, offerings, courses, resources, rooms: price, seller, description) | money defines | marketplace, learning, community |
| `Money\Contracts\EntitlementCheck` (does user X own access to Y: paid, free, gifted) | money | learning, community |
| `Marketplace\Contracts\TutorDirectory` (approved tutors with scope, languages, verification level) | marketplace | community, learning, ai |
| `Learning\Contracts\ProgressFeed` (progress events for insights) | learning | ai, student UI |

## 4. Real-time, live lessons, AI and external services

- The host is shared cPanel: no daemons, no websockets. Screens poll short versioned endpoints (ETag/304), as Dot.Ehail does. Reverb stays installed and unused.
- Live lessons (wave 2): native WebRTC with signalling over polling, peer-to-peer for one-to-one and small groups, as Dot.Engage built; no TURN server exists yet (owner item), so the classroom says when a connection cannot be made and offers the tutor's own meeting link as an honest fallback. No fake video.
- AI (wave 2): an `AiTutor` interface with an Anthropic Messages API driver (configured key and model; off by default) and a null driver; guardrails by age band and academic integrity; outputs labelled as AI guidance. Dot.Agents, Dot.Brain and Dot.Memory are integrated only where a documented cross-app API exists; the audit found none for this use, so they are not called.
- Payments: Paystack (owner decision 2026-10-18), test keys in development, live only behind a second switch. Dot.Billing and Dot.Finance are not called without a documented contract.
- Notifications: in-app, email, Web Push without dependencies (as Dot.Ehail), SMS adapter optional; Dot.Notify events where its ingest contract exists.
