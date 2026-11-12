# R-47 audit: Dot.Tutor as found on 2026-11-10

Method: read every route, controller, service, model, enum, migration, config file and view; ran the suite (293 tests: 286 pass, 7 skipped, 0 fail, SQLite in memory); built the frontend; probed the live host (`/`, `/login`, `/up` answer 200; `/register`, `/dashboard`, `/tutors` redirect to the login; the last deploy, 2026-10-31, was green). Domain agents extend this file with a detailed audit of their own area under "Domain audits".

Classes: **F** fully functional, **I** functional but needs improvement, **P** partially implemented, **V** UI-only or simulated, **B** broken, **M** missing but essential.

## Platform areas

| Area | Class | Finding | Root cause |
|---|---|---|---|
| Public landing | I | One marketing page ("Book a tutor who actually teaches your subject"), Fraunces and Figtree from Google Fonts, Alpine from unpkg | Built as a brochure for a booking tool; CDN dependencies |
| Registration and sign-in | F | InfoDot signed redeem (POST body token), team sync, local registration closed, 2FA, sessions; tested | n/a (kept) |
| Student profile | M | A user row only: no country, curriculum, level, subjects, languages, age or guardian | No learner model |
| Tutor profile | I | One application per user: headline, bio, qualifications as free text, hourly rate (decimal), currency label, timezone, subjects; operator approves, rejects, suspends | Qualifications are unverified text shown as fact; no levels, curricula, languages, formats or offerings |
| Tutor onboarding | P | A single form then a wait for the operator | No guided steps, no evidence upload |
| Tutor verification | P | Operator review of the profile as a whole (approve/reject/suspend with reasons, notifications) | No evidence, no levels of verification, peer and professional tutors indistinguishable |
| Academic levels and grades | B (vs brief) | `subjects.level` is a free string defaulting to `high_school` | No academic structure: no countries, curricula, grades, tertiary levels |
| Subjects | I | Operator-managed catalogue with activate/deactivate; seeded list | Not tied to curriculum or level |
| Tutor discovery and search | P | `/tutors` lists approved tutors, filter by one subject, ordered by rating | Popularity ordering; no level, curriculum, language, budget, availability or format |
| Matching | M | None | Not built |
| Booking and scheduling | I | Weekly availability windows in the tutor's timezone, blocked dates, a slot finder over 14 days, 30-minute grid, overlap guard, pending, confirmed, completed, cancelled, no-show (stale pending sessions expire), throttled | Good core; no group sessions, recurrence, packages, guardian approval or payment step |
| Online learning | M | `delivery` is a label (online or in person); no classroom, no link, no video | Not built |
| Learning rooms and communities | M | None | Not built |
| Courses | M | None | Not built |
| Study materials | P | Files attached to one session (upload, download, delete) for its two participants | Session-scoped only; no library, metadata, licensing or moderation |
| Payments and commissions | M | Rates are stored as decimals and shown as labels; config says "Dot.Tutor does not take payment yet" | Payment provider decided later (now Paystack, per the owner, 2026-10-18) |
| Earnings and payouts | M | None | Not built |
| Reviews and ratings | I | One rating per completed session by the student, 1-5 with a range check, optional review, average on the profile | No moderation, no verified-booking label beyond the session link, no tutor reply |
| Messaging | M | None (only session notes) | Not built |
| Notifications | P | Database notifications for profile decisions and moderation cancellations, a dashboard list, mark read | No email, push or preferences; no booking reminders |
| Assessments and progress | M | None | Not built |
| Admin and moderation | P | Operator flag on users; tutor profile review queue; subject management; dashboard KPIs scoped by role | No safeguarding, content moderation, finance, academic structure or reports |
| Multilingual | M | English only; no lang files; no language data on users, tutors or content | Not built |
| Child safety | M | Every user treated as an adult; no age, guardian, contact restrictions or reporting | Not built |
| Database | I | 19 migrations; decimals for money; history protected from cascading deletes (2026-10-18); rating range check | Small, sound base to extend additively |
| Integrations | I | InfoDot SSO and team sync; Dot.Analytics daily snapshot (`PublishMetrics`); Reverb installed but unused | No Notify, Files, Billing, AI |
| Security | I | Enforced baseline headers, report-only CSP (the signed-in layout loads Tailwind from cdn.tailwindcss.com, Google Fonts and Material Symbols), policies on sessions and resources, throttles on bookings and uploads | CDN dependencies block an enforced CSP |
| Visual design | B (vs brief) | Two identities: a Fraunces landing and a dark Syne/Inter dashboard with Material Symbols, Tailwind from a CDN | No design system |
| Tests | I | 293 tests, good on booking rules, lifecycle integrity, SSO, health, headers | No browser tests, no PostgreSQL run |
| Deploy | F | Manual `deploy.yml` (adopt and deploy modes, CI gate, Vite build on the runner, smoke), `drift.yml`, `server-logs.yml`; green 2026-10-31 | n/a (kept and extended) |

## Preserved

InfoDot sign-in, team sync, closed registration, the scheduling core (availability windows, blocked dates, slot finder, overlap guard, expiry command), the tutor review workflow, session ratings, lesson resources (folded into the new library as session-scoped material), the Analytics snapshot, the security headers, the deploy pipeline, and every existing row: all migrations are additive, decimal money columns gain integer minor-unit companions that are backfilled, and the old free-text `subjects.level` is mapped onto the new academic structure without being dropped.

## Domain audits
(appended by the wave-1 agents)

### Safety domain (safety agent, 2026-11-10)

| Area | As found | Class | Finding | After |
|---|---|---|---|---|
| Age and minors | No date of birth, no age band; every user an adult | M | Brief section 15: "do not assume every user is an adult" | `AgeBand`, `AgeResolver` (verified, guardian, self-declared via seam), limited minor accounts, unknown-age policy switch (D-402) |
| Guardians | None | M | No guardian role, consent or oversight | Signed invitation, InfoDot sign-in, consent with version and hash, revocation, guardian dashboard data, recording consent |
| Guardian approvals | None | M | Minors could book and (later) pay alone | `GuardianApprovals` for booking, purchase, paid room, live lesson with expiry; backstop on the existing booking model |
| Safeguarding gate | None | M | No server-side check before talking, meeting, paying, publishing | `SafeguardingGate` with eight abilities and structured reasons; Laravel abilities `safety.*` |
| Messaging | None (session notes only) | M | No messages, so no restrictions either | Context-bound conversations, guardians copied, contact redaction, private attachments, read state, rate limits, reporting |
| Reporting and moderation | None | M | No way to report anything | `ReportSink` for any model, categories and priorities, child-safety fast path, queue, hide/restore/warn/suspend/escalate/dismiss |
| Incidents | None | M | No escalation procedure | Incidents with response timers, cron escalation, timeline, outside-referral record |
| Tutor screening | Operator approves a profile as a whole | P | No screening for work with minors | Operator-recorded checks with evidence, four-eyes review, required kinds |
| Academic integrity | None | M | No policy or records | Policy (R47_SAFETY.md section 9), integrity cases and sanctions |
| Audit trail | None | M | Moderation and admin actions untracked | Append-only, hash-chained `Audit::record()` used by every domain |
| Account deletion | Cascades or restricts per table | I | Safeguarding evidence must survive deletion | Evidence rows restrict deletion of named people (D-416) |

### Money (money agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| Payments | M | No provider; `config/tutor.php` says Dot.Tutor does not take payment | Paystack hosted checkout, test mode by default, live behind a second switch, server-side verify (R47_MONEY 1-2) |
| Money representation | I | Decimals (`hourly_rate`, `rate`) shown as `ZAR 350.00` labels via `App\Support\Money` | `App\Domain\Money\Money` (integer minor units, NNBSP format); decimals read by string for lessons; the old helper left for existing views |
| Commission | M | None | Versioned rules, per-category overrides, preview endpoint for tutors |
| Earnings, payouts | M | None | Pending/available/paid earnings, manual CSV batch, encrypted bank details, Transfers built and off |
| Refunds, disputes | M | None | Finance-only refunds via Paystack with ledger reversal; dispute records |
| Coupons, credits, bundles | M | None | Tutor- and platform-funded codes with limits; ledger-backed credit; tutor bundles |
| Receipts, ledger, reconciliation | M | None | Gapless receipts; immutable double-entry ledger with database triggers; reconciliation command and settlement sweep |
| Access to paid content | M | None | `EntitlementCheck` seam over `money_entitlements` |
| Guardian-approved payments | M | Every user treated as an adult | Money-side approval seam holds minors; real age and guardian link wait for the safety domain (integration) |
### Learning (courses, library, assessments, progress), learning agent, 2026-11-10

| Item | As found | Class | Finding | After |
|---|---|---|---|---|
| Courses | none | M | No course model, structure or delivery | Courses, sections, 8 lesson types, materials with per-file permission, publish checks, immutable versions, bundles (F, tested) |
| Monetisation of courses | none | M | Rates were labels only | Free/paid with `price_minor`, refund policy shown before purchase, coupons and checkout via money seam (null default answers honestly that payments are off) |
| Protected content | Session files streamed through a policy (good pattern) | I | Only session-scoped | Private `learning` disk, `CourseAccess`, signed user-bound media URLs with range requests, entitlement re-check per request; guessing tests (F) |
| Enrolment, progress, resume | none | M | | Enrolment (free or entitlement), completion, resume with video position, course percent, creator's learner list (F) |
| Certificates | none | M | | Only with a completion rule; public verification by code with name minimisation (F) |
| Course reviews | Session ratings only | M | | Enrolled learners with progress only (F) |
| Study materials / library | `lesson_resources` per session; extension check by client name (`mimes`) | P | No library, metadata, licence, moderation | Library with metadata, sniffed uploads, licence and ownership rules, past-paper rule, tiers only via recorded review, copyright notice/counter/decision, recommendations, search (F). The session-scoped `lesson_resources` are left untouched (marketplace-owned paths) |
| Assessments | none | M | | Question banks (4 types), course quizzes, tutor assessments with assignment rules, practice, server marking, time and attempt limits (F) |
| Flashcards | none | M | | Sets and SM-2 spaced review (F) |
| Progress insight | none | M | | Topic mastery and deterministic insights from real answers; `ProgressFeed` seam (F) |
| Static analysis | Larastan level 5 not in CI; base app fails it | I | | Learning domain adds false positives from `casts()` methods; not gated (noted in R47_LEARNING.md) |
### Academic, languages and i18n (academic agent, 2026-11-10)

| Area as found | Class | Finding | After |
|---|---|---|---|
| Academic levels and grades | B | `subjects.level` free string (primary, high_school, university, adult); no country, curriculum, grade or tertiary level | Countries, frameworks, curricula, ordered levels, stages as data; CAPS, IEB (Gr 1-12), SA higher education (1st year to doctoral, professional), Cambridge seeded; operator-editable |
| Subjects | I | Flat operator catalogue, name + level string, duplicates per level | Global catalogue on the same table (ids kept), aliases, families, language subjects; ~880 offerings per level; legacy rows adopted or linked, legacy level strings mapped, unknown strings reported |
| Topics | M | None | Topic tree per offering; starter topics for CAPS Mathematics Gr 1-12, Physical and Life Sciences Gr 10 |
| Institutions | M | None | Table + operator CRUD; 26 public universities seeded; never a school name for a learner |
| Learner profile | M | User row only | Learner profile (country, curriculum, level, subjects, tertiary institution, birth month only, bilingual flag), one-minute form, describe-what-you-need parser |
| Languages | M | English only, no language data | Languages table (11 official + SASL + pt/fr/sw), holdings by role, tutor teaching languages with self-declared level and operator verification |
| Interface i18n | M | No lang files, hard-coded English | `lang/` architecture, locale middleware (user, session, Accept-Language, English), switcher with measured coverage and provenance; en source, af/fr/pt AI-drafted unreviewed for this domain's strings; other screens still hard-coded English |
| Content translation | M | None | Translation store with provenance, review, out-of-date detection, notation guard, side-by-side API |
| Academic admin | P | Subject screen only | Operator screens for every structure table, audited through a seam |
### Marketplace (marketplace agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| Tutor profile | I | Headline, bio, free-text qualifications, decimal rate, subjects | Profile v2 (approach, experience, formats, area, group sizes, handle, photo), minor-unit rate, teaching scope by subject, curriculum and level range |
| Qualifications | V (vs brief) | Free text shown as fact | Records with private evidence, review status and operator decisions; legacy text labelled self-declared |
| Verification | P | Whole-profile approve/reject only | Five evidence-backed levels assigned by an operator with an append-only audit; labels cannot over-claim |
| Offerings | M | One hourly rate | Eight offering types with minor-unit prices, durations, formats, subjects, levels, cancellation terms |
| Discovery | P | Filter by one subject, ordered by rating | Filters, daily fair rotation, storefront payload with honest review counts |
| Matching | M | None | Explained, deterministic MatchingService with hard filters and nine terms (R47_MATCHING.md) |
| Booking | I | pending/confirmed/completed/cancelled/no_show, overlap guard, slot finder | State machine with guardian, payment, in-progress and dispute states, row locks, append-only events, Idempotency-Key, groups with race-safe capacity, weekly series, package credits, cancellation policy, hold expiry; old forms routed through it |
| Ratings | I | One per session, average of all, no moderation | Reviews held on contact details or reports, tutor reply, moderation, average of published only; the SQLite 1-5 trigger survives the table rebuild |
| Money on bookings | M | Decimal labels | Minor units on profiles, sessions, offerings, seats and packages; payment through the Checkout seam (unconfigured honestly) |
| Tests | I | Booking rules only | 64 marketplace feature tests (booking, groups and the last-seat race, series, packages, matching, verification, reviews, API shape, pages) |

### Design system and public pages (ds agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| Visual identity | B | Two unrelated looks: a Fraunces/Figtree landing on cream and a dark Syne/Inter dashboard with an amber accent | One world (DESIGN.md): reading-room tokens, Archivo and Atkinson Hyperlegible Next, eight stage binding colours, light and night themes |
| Frontend build | P | `@tailwindcss/vite` 4 installed but unused; Tailwind 3 via PostCSS for guest pages and cdn.tailwindcss.com at runtime for signed-in pages | Tailwind 4.3.1 built by Vite for every page; no runtime CDN |
| Fonts and icons | I | Google Fonts on every layout; Material Symbols icon font from Google | Self-hosted OFL fonts in public/fonts; Lucide SVG sprite in public/icons |
| Alpine | I | unpkg Alpine on the landing (Livewire already bundles Alpine) | Removed; the landing needs no Alpine |
| Component kit | M | Jetstream defaults plus per-page inline styles and `t-*` classes in the layout | 31 `x-td.*` components with states and accessibility built in; Jetstream components restyled |
| Shells | P | One app layout (sidebar drawer, good a11y contract) for everyone | Public, learner (phone bottom bar), tutor workspace, operations shells on one frame; navigation as data, only built routes linked |
| Young learners | M | No difference between a Grade 1 learner and an operator | Young, standard and dense steps in one system |
| Landing | I | A brochure for a booking tool with an Unsplash hotlink and unpkg Alpine | Rebuilt around plain-words requests, explained matches, rooms by level, tutors' practice and languages; real content and credited open-licence photographs |
| Error pages | I | Designed, but loaded Google Fonts and Material Symbols | Same pages in the new system, self-contained, no third-party hosts |
| Existing pages | n/a | Dark inline styles per page | Render in the new system through a temporary bridge (legacy.css) until wave 2 rebuilds them |

### AI domain (ai agent, 2026-11-10)

| Area | As found | Class | Finding | After |
|---|---|---|---|---|
| AI assistant | `ANTHROPIC_API_KEY` and `ANTHROPIC_MODEL` in `.env.example`, read by nothing | M | Brief section 14: no assistant existed | `Domain/Ai`: guarded gateway, Anthropic SDK driver (off by default), null driver, student and tutor tasks |
| Guardrails | None | M | Age, integrity, minimisation, limits, logging all missing | Enforced in code and tested (docs/R47_AI.md section 3) |
| Machine translation | Academic `Translations` store ready, no producer; course, resource and tutor profile not registered | P | Brief section 10 | Machine translation with model, unreviewed status, notation check, side-by-side page, review |
| Need parsing | Deterministic parser only | I | Free text it cannot read was a dead end | Optional AI rewording, parser re-match, confirmation step |
| Dot.Agents/Brain/Memory | Not integrated | F (correctly absent) | No supported learning API exists | Recorded, not called (D-916) |
### Integration (integ agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| Every cross-domain port bound to a null default | integration | Matching ignored levels and languages, payments answered 503, guardians could never approve, audit went to logs | All 22 ports bound to owners (R47_INTEGRATION.md section 1) |
| Marketplace asked the gate about the offering | safety bug | `peopleIn(TutorOffering)` is empty, so a minor's booking of an unscreened tutor was only caught by the safety model backstop (generic error) | The adapter asks about the offering's tutor; priced offerings published count as selling |
| Money's booking adapter refused `awaiting_payment` and priced by rate | money/marketplace bug | A v2 booking could not be paid at all | `MarketplaceBookingPurchasable` |
| Checkout without Paystack created an order anyway | money/learning | The open order then blocked quotes ("already in an unpaid order") | No order when payments are off; open orders reused |
| Search used `like` | PostgreSQL bug | Case-sensitive on PostgreSQL | `whereLike` |
| Idempotency stored the full class name in a 40-char column | PostgreSQL bug | Seat, package and series requests failed on PostgreSQL | Class basename |
| JSON errors had four renderers with different codes | consistency | `access_denied_http_exception` vs `forbidden`, redirects for JSON calls outside `api/*` | One policy (D-1404) |
| Academic ids without keys | data integrity | Orphans possible | Keys added (D-1406) |
| Suite memory | CI | Fails at 128 MB on trunk | 512M (D-1405) |

### Live classroom (classroom agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| Live lessons | M | No classroom existed; `delivery = online` lessons had no place to meet and no attendance | `app/Domain/Classroom`: one classroom per booking, WebRTC mesh, signalling by polling (R47_CLASSROOM.md) |
| `meet_live` gate and `live_lesson` approvals (safety) | P | Rules and data existed with no caller | Asked on every page and API call; approvals requested from the pre-join page; tested |
| Recording consent (safety) | P | Stored, no recorder | Recording not built (no safe storage on the host); pages say so (D-805) |
| `Permissions-Policy` | I | Denies camera and microphone app-wide, so a call could never start | The classroom page alone allows them for this origin; elsewhere unchanged (tested) |
| `lesson_resources` | I | Upload limited to the lesson's two people by extension, no content sniffing; group learners could not see files | Classroom files sniff bytes, re-encode images, stream to everyone in the lesson (group seats and guardians included); the legacy controller is untouched |
| Marketplace `start` and `complete` | F | Correct, but nothing in a lesson called them | Tutor joining starts the lesson; the after-lesson page completes it through `BookingFlow` |
| Learning assignment | P | Only a controller (no service); "has taught" ignores group seats | Classroom seam mirrors the rules and counts a learner in the lesson as taught (D-810) |
| Reminders | M | No lesson reminders | `LessonStartingSoon` event once per lesson for comms (D-812) |
| External meeting links | M | No fallback when browsers cannot connect | Tutor's own https link per lesson, shown only inside that classroom |
### Notifications and communication (comms agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| In-app notifications | P | Database notifications for tutor profile decisions and moderation cancellations, a dashboard list, mark read; safety added approval, warning and incident notices | One inbox (`/comms/inbox`, JSON too) for every notification, with type, category, title and subject key; read state; unread count one per subject; badge capped at 9+; `<x-comms::bell />` for the shells |
| Email | P | Only safety's guardian invitation and approval-request mails; host `MAIL_MAILER=log` | Email copy of every notification the person's choices allow, from the queued delivery worker; on a log mailer each row says "not delivered"; lesson emails carry a calendar file |
| Push | M | None | Web Push (RFC 8030/8291/8292) on PHP openssl, adapted from Dot.Ehail; off until VAPID keys are set; per-device subscribe, test, revoke on 404/410 |
| SMS | M | None | Adapter interface with an honest "not configured" default; no numbers collected |
| Booking notifications | M | No booking confirmations, changes or reminders | Every booking state change notifies the other party (and a minor's guardians); 24 h and 1 h reminders by cron, idempotent |
| Payments, payouts, learning, verification, reports | M | None (money and learning raise few or no notifications) | Listeners on OrderPaid/OrderRefunded and thin after-commit observers on payments, payouts, enrolments, assignments, resource reviews and take-downs, verification decisions and reports |
| Preferences | M | None | Per category and channel, defaults, mandatory categories (safety, payments, guardian approvals) shown as facts, quiet hours for push and SMS |
| Minors | M | Every user treated as an adult | Guardian copies of a minor's lessons, purchases and safety notices; children get in-app only; no marketing to minors; tutors see a minor's first name only |
| Dot.Notify | M | Not called | Client for Notify's documented ingest contract, off by default; adults only, ids and titles only |
| Calendar | M | None | `.ics` per lesson (download and email attachment) in the lesson's time zone with a VTIMEZONE |

### Community domain (community agent, 2026-11-10)

| Area | As found | Class | Finding | After |
|---|---|---|---|---|
| Academic learning rooms (brief 5) | Nothing: no rooms, no subject spaces | M | The defining feature did not exist | Subject rooms per offering and audience, stage hubs, profile landing, browsing (docs/R47_COMMUNITY.md) |
| Q&A and discussions | None | M | No way to ask or answer | Questions, answers, accepted answer, resolved state, discussions, explanations, revision techniques, replies, edit history |
| Study material in rooms | Library existed (learning), not linked to any space | P | Material had no room | Material posts link published library items; the room lists library material for its subject and level |
| Study groups and tutor rooms (brief 11) | None | M | No group learning, no tutor-run rooms | Groups with join rules and caps, sessions with calendar files, attendance, revision lists, tasks; free and paid tutor rooms through money |
| Room safety (brief 15) | Safety rules for rooms existed, `RoomDirectory` bound to a null | P | Room conversations answered 404 | Rooms are `AgeGatedSpace`s; `RoomDirectory` bound; age gates, pre-moderation, redaction, reports and holds enforced server side |
| Engagement mechanics | None | F (correctly absent) | Brief: no likes or popularity | Indicators only: questions resolved, answers accepted, resources contributed, sessions attended, milestones |
| Freshness | None | M | No way to see new activity | Versioned activity endpoint with ETag/304 and a polling notice |

### Operations command centre and guardian views (ops agent, 2026-11-12)

| Area | As found | Class | Finding | After |
|---|---|---|---|---|
| Operator roles | One flag, `is_platform_operator`; every operator could do everything | M | No separation of duties (brief 20) | Seven roles, permission matrix over every operator route, team screen, `ops:grant`, audited changes |
| Operator route coverage | Operator routes guarded three different ways (middleware, gates, inside controllers) | I | A new route could be open to every operator unnoticed | Router scan test; guard before route binding; gate answers from the role |
| Admin overview | Legacy dashboard with a few counts | P | No business intelligence (brief 20) | Real-data overview: queues, people, lessons, weekly chart, money (finance), languages, geography, satisfaction, retention |
| Users and minors | Safety JSON endpoints only | P | No screen for restrictions, minors, guardians | Users list with filters, account page with history and actions, minors page with the rules in force |
| Verification | Minimal marketplace page and legacy tutor-profile page | P | Separate pages, no level setting in a screen, no screening status beside it | One verification area: evidence, applications, languages, tutor record with levels, decisions and screening status |
| Safeguarding | Safety queue and incident pages (minimal) | P | No integrity, screening or minors screens; no fast path emphasis | Reports with the child-safety fast path, incidents with timers, integrity, screening (four eyes), AI log tab |
| Content | Community held-post page, learning moderation JSON | P | Resource reviews and copyright complaints had no screen | Content area: held posts, resource reviews and tiers, complaints and takedowns, courses |
| Money | Finance JSON API and a minimal page | P | Refunds, payouts and exceptions only through the API | Orders, refunds (finance only, idempotent forms), exceptions incl. unmatched payments, disputes, commission rules, payouts and batches |
| Audit viewer | JSON endpoint | P | No screen for audit history | Filtered audit screen with the chain check |
| Guardian experience | Minimal safety page, no money orders | P | Not phone-first, purchases said "not connected" | Guardian home and child pages in the learner shell, decisions with reasons, orders, notices, settings |
