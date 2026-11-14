# R-47 Dot.Tutor: final implementation report

For the owner. Written 2026-11-12 from the trunk `feat/r47-tutor`; final numbers are from `e4d7d64`, after the polish wave and the fix round. It follows section 27 of the brief (`docs/R47_BRIEF.md`) item by item, in order, and then adds the definition of done, external services, the jury, owner steps and the deployment record.

Every claim points at its evidence: a test file, a domain document section, or a browser end-to-end spec in `tests/e2e/specs`. Where the evidence is weaker than a test or a browser check, the item is marked Partial at most. Nothing here is a legal compliance claim.

## How to read the status words

| Word | Meaning |
|---|---|
| **Implemented** | Built and proven by an automated test or a recorded browser check. |
| **Partial** | Built in part, or built but not proven against the real provider, device or person it depends on. |
| **Planned (not built)** | Not in the code. Recorded as a decision or an owner item. |

## At a glance

- The app was a booking tool with 293 tests and no rooms, courses, payments, languages, child safety or matching. It is now one platform with 15 domains under `app/Domain`, 26 new migrations (all additive), 418 recorded decisions and 1,034 PHP tests (1,027 pass, 7 skipped, 0 fail on both SQLite and PostgreSQL).
- All six brief journeys (student, tutor, course creator, learning community, multilingual, safety) pass in a real Chrome against stubs of Paystack, InfoDot, Anthropic and SMTP, with the internet refused. Nine failure scenarios pass too.
- Nothing has been run against a live Paystack account, a real Anthropic key, a real mail server, a TURN relay, a real phone, Safari or Firefox, or a screen reader.
- The site is safe to deploy in its honest "off" state: free lessons, courses, library, rooms and the classroom work at once. Paid lessons need Paystack keys, mail and tutor offerings first (owner steps, section 29).
- Legal review, native-speaker review and academic editor review have not happened. They are owner items.

---

## 1. Initial platform audit

Source: `docs/R47_AUDIT.md` (method, 30 platform areas, then one audit per domain).

The audit read every route, controller, service, model, migration, config and view, ran the suite (293 tests: 286 pass, 7 skipped) and probed the live host (last green deploy 2026-10-31).

| Class (brief section 2) | Areas as found |
|---|---|
| Fully functional | InfoDot sign-in and team sync, the deploy pipeline |
| Functional, needs improvement | Landing page, tutor profile, subjects, booking and scheduling core, reviews, database, integrations, security headers, tests |
| Partially implemented | Tutor onboarding, verification, discovery, study materials (session files only), notifications, admin |
| UI-only or simulated | Qualifications (free text shown as fact) |
| Broken (against the brief) | Academic levels (a free string), visual design (two unrelated looks, Tailwind from a CDN) |
| Missing but essential | Learner profile, matching, online lessons, rooms, courses, payments, earnings and payouts, messaging, assessments, multilingual, child safety |

What was kept: InfoDot sign-in, the scheduling core (availability, blocked dates, slot finder, overlap guard), the tutor review workflow, session ratings, lesson files, the Analytics snapshot, the security headers, the deploy pipeline and every existing row. Money columns gained integer cent companions, backfilled; the old decimals stay. The old free-text subject level was mapped, never dropped.

Later audits found and fixed real defects in the build itself. Examples: the integration step found that a v2 booking could not be paid at all and that a minor's booking of an unscreened tutor was caught only by a backstop (`docs/R47_INTEGRATION.md` section 9); QA found a 500 on the tutor's assessment page and an empty "Tutors" tab in Grade 11 rooms (`docs/R47_QA.md` section 6).

## 2. Competitive research findings

Source: `docs/R47_RESEARCH.md`. Status: **Partial**. The legal reading cites primary or statute texts with URLs. Most competitor facts are desk research marked "(unverified)" and must be checked before anyone quotes them.

| Platform | What Dot.Tutor took | What it avoided |
|---|---|---|
| Preply, Wyzant | Visible commission; tutors set prices | Opaque ranking; newcomers forced to compete on price; taking a whole first lesson |
| Superprof | Free first contact is possible | Paying to message; easy-to-misread "verified" badges |
| Varsity Tutors, Tutor.com | Low effort for families | No tutor storefront; no learner choice |
| SA agencies (Teach Me 2, Master Maths) | CAPS and IEB knowledge | Opaque pricing; language of teaching never a first-class choice |
| Udemy, Coursera, Khan, Brilliant | Course structure, refund rule before purchase, mastery from answers, practice as a lesson type | Perpetual discounting; leaderboards |
| Quizlet, Chegg | Licence and provenance at upload, take-down from day one | Hosting copyrighted answer keys |

The Dot.Tutor strategy that came out of it: explained matching with no paid placement; curriculum and level as data; teaching language as a matching dimension; verification levels from evidence; a capped new-tutor term so newcomers are findable; many low-commitment formats; a storefront a peer tutor can grow into a business.

Legal context read (not advice): Children's Act registers (Form 29, not Form 30 as the brief said), the National Register for Sex Offenders, POPIA sections 34 and 35, Films and Publications Act s24C and s27A, CPA and ECT cooling-off. Twelve questions for a South African lawyer are listed in the research note. None has been answered.

## 3. Student experience improvements

Sources: `docs/R47_UI_STUDENT.md`, `tests/Feature/Student/StudentScreensTest.php` (14 tests), E2E spec 01.

| Improvement | Status | Evidence |
|---|---|---|
| `/learn` home that says what to do next (next lesson, work due, courses to resume, unanswered room questions, insights) | Implemented | `StudentScreensTest`, E2E 01 |
| One-minute onboarding at `/learn/welcome` with a guardian step for minors | Implemented | E2E 01 steps 2 to 4, E2E 06 step 1 |
| Say the need in plain words ("Grade 11 Maths in isiZulu under R300") and get explained matches with fits, gaps and a "How this was ranked" table | Implemented | E2E 01 steps 5 and 6 |
| Storefront with real open times, prices, qualifications and honest labels | Implemented | E2E 01 step 7 |
| Booking from real slots, packages, group seats and weekly series, idempotent | Implemented (packages and series by feature tests only) | `StudentScreensTest`, marketplace tests |
| Lesson page with timeline, pay, cancel with refund preview, review, calendar file, classroom link | Implemented | E2E 01 steps 8 to 11 |
| Courses, library, practice, flashcards (SM-2), progress in words with no leaderboard | Implemented | E2E 01 step 12, `StudentScreensTest` |
| Purchases and receipts, account and languages page | Implemented | `StudentScreensTest` |
| Young step for Grades 1 to 6 (19 px text, 56 px controls, fewer items, simpler words) | Implemented | `test_a_grade_3_learner_gets_the_young_step...`, a11y young-copy check |
| Sign-in lands on the role's home, not `/dashboard` | Implemented | `RoleHomeAfterSignInTest` (D-1801) |

Not built: reschedule as one action (the marketplace has none); a learner's own time zone (platform time SAST is shown and labelled, D-1104).

Jury round 1 raised learner issues: a wall of slot buttons on the booking page, the same seven reasons repeated on every match card, a two-hour difference between an order line and its notification, adult wording on the young step, and the team switcher shown to learners. They are fixed on `feat/r47-polish-learner`, merged. See section 27.

## 4. Tutor experience improvements

Sources: `docs/R47_UI_TUTOR.md`, `tests/Feature/Teach/*` (13 tests), E2E specs 02 and 03.

| Improvement | Status | Evidence |
|---|---|---|
| Nine-step guided onboarding with a draft state (profile, photo, scope by level, teaching languages, qualification evidence, under-18s, hours, first offering, send) | Implemented | `TutorWorkspaceTest`, E2E 02 steps 1 to 7 |
| Today screen: requests waiting, lessons needing action, money at a glance, minors' requests as a number only | Implemented | `TutorWorkspaceTest`, E2E 02 step 8 |
| Bookings by state, accept and decline with reason, group lessons, packages | Implemented | E2E 02 step 9, E2E F3 |
| Calendar in the tutor's zone; students list with private notes | Implemented | `TutorWorkspaceTest` |
| Eight offering types with a live "you keep" from the server's commission rule | Implemented | E2E 02 step 7 ("you keep R255.00") |
| Storefront editor, share link `/u/{handle}`, referral link (attribution only), printable promo page | Implemented | `TutorWorkspaceTest` |
| Course studio (builder, uploads with progress, quizzes, price, publish checks, versions, learners) | Implemented | `CourseStudioAndMaterialsTest`, E2E 03 |
| Materials, question banks, assessments assigned to the tutor's own students | Implemented | E2E 01 step 12 |
| Earnings with pending, available and paid; income CSV; payouts with statements; bank details (last four only, recent sign-in) | Implemented | E2E 02 steps 11 and 12 |
| Insights from real rows (requests per week, answers, net per month, returning students) | Implemented | `TutorWorkspaceTest` |

Not built: reschedule; referral rewards; storefront view analytics (no tracking added); recurring subscriptions; mixed bundles of lessons and digital items as a screen; editing a lesson in place and reordering sections in the course builder.

Jury round 1 raised tutor issues: phone tables hiding money and actions, two shells for one tutor (the legacy `/tutor/profile` and `/tutor/availability`), three different verification wordings, a legacy line saying payment happens outside the platform, earnings rows naming the tutor instead of the learner, an 80-pill subject picker, and developer words on operator screens. They are fixed on `feat/r47-polish-teach`, merged. See section 27.

## 5. Academic room architecture

Sources: `docs/R47_ACADEMIC.md`, `docs/R47_COMMUNITY.md`, `tests/Feature/Academic/*` (68 tests), `tests/Feature/Community/*` (31 tests), E2E 04.

**Structure as data.** Countries, qualification frameworks, curricula, ordered levels and stages are tables, not code. `php artisan academic:sync` seeds South Africa CAPS and IEB (Grades 1 to 12), South African higher education (first year to doctoral, plus professional) and Cambridge as a second example. 71 subjects, about 880 subject-at-level offerings, starter topics, 26 public universities and 15 languages. Operators edit everything at `/ops/academic`; the sync never overwrites an edit and never deletes. Legacy subjects keep their ids (`LegacySubjectMappingTest`).

**Rooms.** One subject room per offering and audience (`under_18` or `adult`), created on first visit. Stage hubs list every subject at a level. "My rooms" lands a learner in the rooms of their profile. Every read, post and join asks the safeguarding gate server-side.

| Part | Status | Evidence |
|---|---|---|
| Grade 1 to doctoral structure, multi-country capable | Implemented | `AcademicReferenceDataTest`, E2E 04 |
| Subject rooms per level and audience, stage hubs | Implemented | `RoomsAndEligibilityTest`, E2E 04 steps 1 and 2 |
| Questions, answers, accepted answer, discussions, explanations, revision techniques, edit history | Implemented | Community tests, E2E 04 steps 3 and 5 |
| Material posts linking published library items | Implemented | E2E 04 step 4 |
| Tutor and course recommendations by fit, never popularity | Implemented | E2E 04 step 6 (after the QA fix D-1809) |
| Study groups (caps, join rules, sessions with `.ics`, attendance, revision lists, tasks) | Implemented by feature tests; not driven in a browser | `test_study_groups_have_a_size_cap...` |
| Free and paid tutor rooms and workshops (paid through money, guardian first for minors) | Implemented by feature tests | `test_a_paid_room_is_bought_through_money...` |
| Pre-moderation of under-13 posts, contact redaction, reports, three-reporter hold | Implemented | Community tests, E2E 04 step 7 |
| Live chat inside rooms | Planned (not built). Polling shows "new activity" every 30 s; no websockets on the host |
| Postgraduate rooms | Partial: data exists, no journey drove one |

Not seeded on purpose: Grade R, Second Additional Language subjects, the smaller FET electives, TVET and private institutions, schools. Topics exist only for CAPS Mathematics Grades 1 to 12 and Grade 10 Physical and Life Sciences. Subject names were written from the build agent's knowledge and need an academic editor's review.

## 6. Tutor matching implementation

Sources: `docs/R47_MATCHING.md`, `tests/Feature/Marketplace/MatchingTest.php`, E2E 01 and 05.

`MatchingService` applies seven hard filters (approved, teaches the subject, format and area, teaching language, budget within 20 %, level within one step, help type), then scores nine named terms that sum to 100: scope 25, curriculum 10, language 15, availability 15 (from the real slot finder), budget 10, help type 5, verification 8, reviews 7 (only from 3 published reviews, saturating at 10), new tutor 5 (capped below reviews). Every result carries every term and plain reasons. Ties break by a hash of the need and the tutor, so no tutor is always first.

| Rule | Status | Evidence |
|---|---|---|
| No paid placement (no boost or featured column) | Implemented | asserted in `MatchingTest` |
| Teaching language, never the interface locale | Implemented | E2E 05 steps 2 and 3 (interface Afrikaans, isiXhosa tutor found, English-only tutor left out) |
| Plain-words need parsing (deterministic `NeedParser`, optional AI reword confirmed by the parser) | Implemented (AI part against a stub only) | Academic `NeedParser` tests, E2E 01 step 5 |
| Matching cost at scale: 1,219 statements and 785 ms down to 35 and 262 ms on PostgreSQL | Implemented | `ListQueryBudgetTest`, `docs/R47_PERF.md` 2.1 |
| "Previous tutoring outcomes" as a term | Planned (not built). No outcome data exists yet; not faked |

Limits: 300 candidates scored and 20 returned per request; "new tutor" uses the approval time, which a suspend and reinstate also updates.

## 7. Course marketplace

Sources: `docs/R47_LEARNING.md`, `tests/Feature/Learning/*`, E2E 03 and F5.

| Part | Status | Evidence |
|---|---|---|
| Draft course with subject, curriculum, level, original language, objectives, workload, completion rule | Implemented | E2E 03 steps 1 and 4 |
| Sections and eight lesson types (reading, video upload, external video link, document, image, slides, practice, quiz) | Implemented | E2E 03 step 2, learning tests |
| Materials with per-file permission; private disk; signed, user-bound media URLs with range requests | Implemented | E2E 03 step 3, E2E F6 (guessed URLs refused) |
| Publish checks in plain words; immutable versions; enrolments pinned to a version | Implemented | E2E 03 step 6 |
| Free or paid with a refund policy shown before purchase; course bundles; coupons through money | Implemented (bundles and coupons by feature tests only) | E2E 03 steps 5 and 7 |
| Enrolment, progress, resume, certificates (verify by code, initials only, "not an accredited qualification") | Implemented | E2E 03 step 8 |
| Course reviews by enrolled learners with progress | Implemented | learning tests |
| Refund revokes access at once | Implemented | E2E F5 |
| Creator earnings released after the refund window | Implemented | E2E 03 step 9 |
| Video transcoding, streaming formats, DRM | Planned (not built). No ffmpeg or workers on the shared host; "view only" is not DRM and the builder says so |
| Embedded external video | Planned (not built). Links only; the CSP has `frame-src 'none'` |
| A report button on courses | Planned (not built). Library items have one; courses do not (`docs/R47_INTEGRATION.md` section 4) |

## 8. Study material library

Sources: `docs/R47_LEARNING.md` (resource library), E2E 04 step 4, E2E 05 step 4, E2E F6.

| Part | Status | Evidence |
|---|---|---|
| Upload typed by bytes (PDF, OOXML without macros, OpenDocument, images re-encoded, MP4/WebM), size caps, SHA-256 | Implemented | learning tests |
| Metadata: country, curriculum, level, subject, topic, language, type, difficulty; search and filters | Implemented | learning tests |
| Licence and ownership rules (CC BY, CC BY-SA, all rights reserved, public domain, official source); others' work cannot be sold | Implemented | learning tests |
| Past exam papers only as "official source" with the publisher's link, held for review | Implemented | learning tests |
| Quality tiers that exist only after a recorded review; owners cannot review their own | Implemented | learning tests |
| Copyright notice, counter-notice, decision; upheld files blocked by hash | Implemented | learning tests |
| Original language shown, translation side by side | Implemented (machine translation against a stub) | E2E 05 steps 4 to 6 |
| Virus scanning | Planned (not built). No scanner on the shared host; uploads say so |
| Resumable uploads (brief section 23) | Planned (not built). Course uploads show a progress bar only (D-1212) |

Known gap: a minor uploading a priced resource is not caught by the commercial rule at upload (course publish and bundles are). Learning owner item (`docs/R47_INTEGRATION.md` section 4).

## 9. Multilingual capabilities

Sources: `docs/R47_I18N.md`, `docs/R47_ACADEMIC.md`, `docs/R47_AI.md` section 6, E2E 05.

| Part | Status | Evidence |
|---|---|---|
| Interface locale separate from teaching language; teaching language drives matching | Implemented | E2E 05 steps 1 to 3 |
| Languages as data: 11 official written languages, South African Sign Language (teaching only), Portuguese, French, Swahili | Implemented | academic tests |
| Tutors hold "can teach in" with a self-declared level and optional operator verification | Implemented | E2E 02 step 4 |
| Content keeps its original language; translations stored with provenance (author, human, machine with model), review status, out-of-date detection | Implemented | academic translation tests |
| `NotationGuard` rejects a translation that drops a formula, LaTeX segment or number | Implemented | academic and AI tests |
| Machine translation of courses, resources, tutor profiles and room posts, labelled as machine | Partial: built and driven end to end against a local stub of the Anthropic API; never run on a real model; off until the AI key is set | E2E 05 step 5 |
| Interface strings: English complete | Implemented | `LocaleTest` (coverage honesty) |
| Afrikaans, French, Portuguese interface strings | Partial: drafted by the build agent (an AI) for the academic strings only, labelled "AI-drafted, not yet checked" | `docs/R47_I18N.md` section 2 |
| isiZulu, isiXhosa, Sepedi, Setswana, Sesotho, Xitsonga, siSwati, Tshivenda, isiNdebele, Swahili interface strings | Planned (not built). 0 % and labelled "Not translated yet, shown in English" |
| Every screen keyed for translation | Partial: `interface_fully_keyed` is false; most screens still hold English text |

Honest summary: multilingual works beyond labels for discovery, content and provenance. The interface itself is English in practice.

## 10. AI integrations

Sources: `docs/R47_AI.md`, `tests/Feature/Ai/*` (39 tests), E2E 05 step 5, E2E F9.

Status: **Partial**. The code is a real integration (official Anthropic PHP SDK, model `claude-opus-5-5`) behind a guarded gateway. It is **off by default** and has only ever answered from a local stub. Nothing is sent anywhere until `AI_ENABLED=true` and `ANTHROPIC_API_KEY` are set.

| Part | Status | Evidence |
|---|---|---|
| Student help: explain, simplify, practice, revision summary, study plan from real mastery, explain my mistake, help with a library resource | Partial (stub only) | `StudentAssistanceTest` |
| Tutor help: lesson outline, revision exercises, course description, quiz drafts (accepted by the tutor before they exist), struggle summary without names, resource suggestions from real items | Partial (stub only) | `TutorAssistanceTest` |
| Guardrails enforced server-side: age bands, guardian consent, "help me understand" only for minors, integrity refusal during assessments, data minimisation, contact redaction, daily and monthly cost limits, safeguarding signals with Childline 116, report button, encrypted log purged after 180 days | Implemented | `AiGuardrailsTest`; E2E F9 (refused before any call) |
| Request shape against the real SDK (fake transport) | Implemented | `AiDriverTest` |
| Streaming answers, background jobs | Planned (not built). Answers wait in the request up to 60 s |
| Lesson summary drafting in the classroom | Planned (not built). Seam exists, null default, page says so |
| Maths rendering (LaTeX shown as written) | Planned (not built) |
| Dot.Agents, Dot.Brain, Dot.Memory | Not integrated. Inspected 2026-11-10: no documented, consented API for learning context exists (D-916) |

## 11. Safeguarding and child protection

Sources: `docs/R47_SAFETY.md`, `docs/R47_SECURITY.md`, `tests/Feature/Safety/*` (110 tests), `tests/Feature/Security/ChildSafetyBypassTest.php`, E2E 06, F2, F7, F8.

| Part | Status | Evidence |
|---|---|---|
| Age bands (child under 13, teen 13 to 17, adult); birth month only | Implemented | `AgeAndStandingTest` |
| Unknown age protected as a child and asked for a birth month (`ask`, D-1502) | Implemented | `ChildSafetyBypassTest` |
| A learner cannot age themselves out of protection; a second guardian cannot move a confirmed birth date | Implemented | `ChildSafetyBypassTest` (S-04, S-06) |
| Guardian invitation by signed email link, InfoDot sign-in, consent with version and text hash, revocation | Implemented | `GuardianLinkingTest`, E2E 06 step 2 |
| Guardian approvals for bookings, purchases, paid rooms, live lessons, with expiry | Implemented | `GuardianApprovalsTest`, E2E 06 step 3, E2E F2 |
| `SafeguardingGate` on every talk, meet, pay, publish, upload, join; server-side | Implemented | `SafeguardingGateTest`, E2E 06 step 4, F7, F8 |
| Tutors and adults need a current screening to meet minors | Implemented (screening is recorded by operators, see below) | `SafeguardingGateTest` |
| Messaging only in a context (booking, room, support); guardians copied; contact details hidden, look-alike characters folded | Implemented | `MessagingTest`, `ContentAndContactTest` (S-15) |
| Reports with a child-safety fast path; moderation queue; incidents with timers and automatic escalation | Implemented | `ReportsAndModerationTest`, `IncidentsTest`, E2E 06 steps 5 and 6 |
| Academic integrity cases and sanctions | Implemented (records only) | `ScreeningAndIntegrityTest` |
| Append-only, hash-chained audit log, verified daily | Implemented | `AuditLogTest` |
| Guardian experience (home, child, approvals with recent decisions, safety page, messages, payments) | Implemented | `tests/Feature/PolishGuardian/*`, `GuardianViewTest` |
| Tutor screening against the Child Protection Register and the Sex Offenders Register | Partial: operators check off-platform (Form 29, Form 8) and record it with evidence and a second reviewer. Nothing is automated; no public lookup exists |
| Document-based age verification, guardian identity verification | Planned (not built). Age is self-declared or guardian-confirmed; a child could invite a second address they control (owner item) |
| Plagiarism, AI-text and image-abuse detection | Planned (not built). Not claimed |
| Lesson recording | Planned (not built). Every classroom page says "This lesson is not recorded" (D-805) |

No lawyer has reviewed the consent text, the recording consent, the integrity policy or the guardian model.

## 12. Payment and commission architecture

Sources: `docs/R47_MONEY.md`, `tests/Feature/Money/*`, `tests/Feature/Security/MoneyIntegrityTest.php`, E2E 01 step 9, 02 steps 11 and 12, 03 steps 7 and 9, F1, F5.

| Part | Status | Evidence |
|---|---|---|
| Integer cents and an ISO currency everywhere; one money format (`R1 250.00`) | Implemented | `MoneyTypographyTest`, money tests |
| Orders priced on the server, idempotency key required, row locks | Implemented | money tests, `ConcurrencyInvariantsTest` |
| Paystack hosted checkout; paid only when Paystack's verify says success and reference, amount and currency match | Partial: code is the real integration; tested against `FakePaystack` and an HTTPS stub of its documented API; never against a Paystack account | E2E 01 step 9, F1 |
| Webhook: HMAC-SHA512 over the raw body, stored once, processed by a queued job that re-verifies | Implemented (against the stub) | E2E F1b (duplicate webhook changed nothing) |
| Test mode by default; a live key is refused unless `PAYSTACK_ALLOW_LIVE=true` | Implemented | money tests, `tutor:preflight` |
| Double-entry ledger, append-only, database triggers, balanced journals, daily reconciliation | Implemented | `assertLedgerHealthy`, 150-step property test |
| Versioned commission rules per category, preview before publishing (15 % placeholder default) | Implemented | E2E 02 step 7, E2E 03 step 5 |
| Earnings: pending, then available after delivery plus 24 h (lessons) or the refund window (digital) | Implemented | E2E 02 step 11, E2E 03 step 9 |
| Payouts: manual weekly bank-file batch, marked paid by finance | Implemented | E2E 02 step 12 |
| Paystack Transfers for payouts | Partial: built, off by default, tested against the stub only |
| Refunds (finance only, through Paystack, ledger reversal, access revoked), disputes, coupons, credits, gapless receipts | Implemented | E2E F5, money tests |
| Minors' purchases wait for a guardian | Implemented | E2E 06, money and integration tests |
| Recurring subscriptions | Planned (not built). D-511: needs saved cards, dunning and a guardian mandate. Packages and weekly series cover the use |
| Paystack split payments, saved cards | Planned (not built), by decision |
| Dot.Finance | Not integrated (no documented contract for Tutor) |

## 13. Dot ecosystem integrations

| App | State | Evidence |
|---|---|---|
| InfoDot (identity) | Implemented. The only sign-in. Signed POST redeem, server-to-server with this platform's secret. R-47 fixed an account takeover through email change (S-01) | `InfoDotRedeemTest`, `IdentityTakeoverTest`, E2E 01, 02, 06 against an InfoDot stub |
| Dot.Notify | Partial: client for Notify's documented ingest contract, six event types, adults only. Off until configured; Notify's catalogue has no `tutor.*` entries yet | `docs/R47_COMMS.md` section 6 |
| Dot.Analytics | Partial: the pre-R-47 daily snapshot (`tutor:publish-metrics`) is kept unchanged and is off until configured. No new R-47 metrics are sent |
| Dot.Files | Not integrated. Files live on this app's private disks, served by authorising controllers |
| Dot.Finance | Not integrated. No documented contract. Finance can export the trial balance |
| Dot.Agents, Dot.Brain, Dot.Memory | Not integrated (D-916). No documented, consented API for learning context; Brain has no serving handler; Memory holds no learner preferences or minors' consent model |
| Dot.Pulse | Not integrated |
| Dot.Engage | Code adapted, not called: the classroom's WebRTC client was copied from Engage's R-39 meeting room |
| Dot.Ehail | Code adapted, not called: Web Push, money patterns, perf and a11y tooling, backups |

## 14. Frontend and motion skills actually applied

Source: `docs/R47_SKILLS.md` only.

| Skill | Invoked by | What it did |
|---|---|---|
| `impeccable` | Every agent that touched UI: ds, safety, money, learning, academic, marketplace, ai, classroom, comms, community, student UI, ops, tutor UI, qa, security, perf/a11y, polish-guardian | Context, craft floor, operate, shape, harden and polish references read before UI code; `impeccable detect` run on changed views (no findings left, or each finding fixed); a fresh `impeccable-finish-reviewer` subagent on the design system (eight material fixes, all applied; no verdict pass afterwards) |
| `hallmark` | ds agent only | Floor applied (tokens only, no eyebrows, no dashes in copy, reduced motion, content without script); `sloplint` 0 FAIL. Render tier not run (no puppeteer-core) |
| `design-taste-frontend` | ds (landing page run), community, student UI, ops, tutor UI, polish-guardian (app-screen rules) | Landing direction dealt and linted; app-screen rules applied (one question per screen, finding first, KPIs as type, honest zeros) |
| `design-md` | ds agent | `DESIGN.md` in Google format, lint 0 errors, 0 warnings |
| `dataviz` | ops, tutor UI | One series, one hue, table view under every chart, palette contrast validated |
| `claude-api` | ai agent | SDK, model, effort, structured outputs, refusal fallback |
| `security-audit` | security agent | Guidance mode: evidence bar and severity anchors |

Not used, and why: `ui-ux-pro-max` and `emil-design-eng` were **unavailable** (dangling symlinks on this machine); they are not claimed. `awesome-design-md` and `open-design` (no brand reference needed). `gsap-*`, `scroll-world`, `react-bits`, `canvas-ui` (no motion choreography or WebGL: the design direction is "motion is state only, never in the way of reading"). So **no motion skill was applied**. Motion in the product is limited to state transitions and one headline rise on the landing, all cut under reduced motion.

The owner was never interviewed by the design skills (standing no-questions order). `PRODUCT.md` was written from the brief with every fact marked inferred.

## 15. Laravel Boost backend improvements

Honest position: `laravel/boost` (^2.5) is a dev dependency and `CLAUDE.md` carries the Laravel Boost guidelines. The agents followed those conventions (sibling patterns, Pint, feature tests, domain providers). **The domain documents do not record any call to Boost's MCP tools**, so this report cannot claim Boost was used as an assistant. Boost was switched off for the browser suite because its dev logger injects a script into pages (D-1806).

Backend improvements made, by area (brief section 22):

| Improvement | Where |
|---|---|
| Domain boundaries: Academic, Marketplace, Safety, Money, Learning, Community, Classroom, Ai, Comms, Student, Teach, Ops, Integration, Security, Perf, each with its own provider, policies, limiters, scheduled commands | `docs/R47_ARCHITECTURE.md` |
| Cross-domain seams with honest null defaults, bound to real owners by one integration provider registered last | `docs/R47_INTEGRATION.md` section 1 |
| Policy-based authorisation and a router-wide map: every one of about 690 routes classified; a stranger sweep over 400+ id-bearing routes | `RouteAuthorizationMapTest` |
| Operator roles and a permission matrix enforced before route binding | `OperatorRouteMatrixTest` |
| Idempotency keys on every booking and money POST, unique in the database | marketplace and money tests, `ConcurrencyInvariantsTest` |
| Booking state machine with row locks and an append-only event log | `docs/R47_MARKETPLACE.md` |
| Double-entry ledger with database triggers | `docs/R47_MONEY.md` section 3 |
| Hash-chained audit log for every moderation, verification, money and admin action | `AuditLogTest` |
| Secure file handling: private disks, byte sniffing, image re-encoding, authorise-then-stream | `docs/R47_SECURITY.md` section 5 |
| Queue and cron based processing for a shared host (14 scheduled commands under one `schedule:run`) | `docs/R47_OPERATIONS.md` |
| One JSON error shape `{error, message}` | D-1404 |
| Academic foreign keys added with an orphan check first | D-1406, `AcademicForeignKeysTest` |
| 180 unindexed foreign keys reviewed; lookup indexes added | D-1607 |
| PostgreSQL 16 as well as SQLite: suite, upgrade rehearsal and concurrency tests on both | `bin/test-pgsql.sh`, `.github/workflows/ci.yml` |

Not done: Larastan/PHPStan is not in CI (the base app already fails level 5; learning models give false positives).

## 16. Security improvements

Source: `docs/R47_SECURITY.md`, `docs/R47_PRIVACY.md`, `tests/Feature/Security/*` (34 tests).

27 findings fixed, each with a regression test: **6 high, 16 medium, 1 low-medium, 4 low**.

| Id | Severity | What was wrong | Now |
|---|---|---|---|
| S-01, S-02 | High, medium | Changing your email to someone else's let you capture their account at their first InfoDot sign-in | A changed address loses verification; adoption wipes the squatter's password, 2FA, tokens and sessions |
| S-03 | High | Unknown age was treated as adult | Unknown age is protected as a child (`ask`) |
| S-04 | High | A child could type an adult birth year | Correction only towards a younger band |
| S-05 | High | A guardian of any child skipped screening around other children | Exempt only as guardian of every minor present |
| S-06 | High | A second "guardian" could re-age a child | First confirmed birth date stands; existing guardians emailed |
| S-07 | Medium | Threads outlived their room membership or booking | Context re-checked on every message |
| S-08, S-09, S-14, S-15 | Medium | Contact details reached minors through bios, meeting links, lesson notes and look-alike characters | Redacted; meeting links limited to known meeting hosts |
| S-10 to S-13 | Medium | Disputed lessons paid out; refunded packages and room places still usable; bank change without step-up | Fixed; bank change needs a recent sign-in, notifies, holds 72 h |
| S-16 | Medium | Another tutor's series id answered 200 | Refused up front |
| S-17, S-18 | Medium | `javascript:` links stored; quiz answer keys harvestable | https only; keys after a pass |
| S-19 | Low-medium | Full names of minors shown to course authors and classmates | First name and initial |
| S-20, S-21, S-22 | Medium, high, low | No data export; account deletion failed for anyone with records; no retention purge | Export, closure that keeps money and safeguarding records, daily privacy purge |
| S-23 | Medium | Children's homework photos kept GPS data | Re-encoded |
| S-25, S-30, S-31 | Low | Local disk served by signed link; avatars sent initials to ui-avatars.com; cookie not Secure by default | Fixed |
| S-29 | Medium | CSP report-only with `unsafe-inline` | Enforced nonce CSP; `unsafe-eval` only on Livewire pages; render sweep of 150+ pages; zero violations in the browser |

Accepted or left to the owner (recorded, section 2 of the security doc): guardian identity, a child's first self-declaration, the 24 h earnings hold against a 7-day dispute window, half-cent rounding on repeated partial refunds, coupons reserved by held orders, certificates never revoked, study groups among minors not pre-moderated, SSO same-site fallback (ecosystem-wide), no virus scanner, the host `.env` possibly still saying `SECURITY_CSP_REPORT_ONLY=true`.

## 17. Performance improvements

Source: `docs/R47_PERF.md`. Measured on an Apple M4 with a perf world of 4,000 learners, 400 tutors and 24,000 lessons. Host capacity is an **estimate**, not a measurement.

| Page (PostgreSQL) | Before | After |
|---|---|---|
| Matching, 300 candidates | 785 ms, 1,219 statements | 262 ms, 35 statements |
| My bookings | 97 ms, 262 | 30 ms, 16 |
| Ops people list | 44 ms, 237 | 16 ms, 16 to 20 |
| Ops overview | 410 ms | 95 ms |
| Tutor insights | 400 ms | 50 ms |
| Tutor students (2,400 learners) | 1,317 KB HTML | 49 KB (paged) |

| Front end, throttled phone, Fast 3G, gzipped | Bytes | First paint |
|---|---|---|
| Sign in | 134 KB | 1.44 s |
| Learner home | 137 KB | 1.45 s |
| Course player | 138 KB | 1.46 s |
| Classroom pre-join | 162 KB | about 1.5 s |
| Landing | 285 KB | 1.88 s |

Total blocking time 0 ms on every page. `app.js` is 5 KB gzipped; the classroom adds 21 KB. Every measured page meets the budgets (HTML 30 KB, CSS 35 KB, JS 30 KB, first paint 2 s).

Polling: a live-lesson poll no longer rewrites the session row and carries 0.45 KB of headers instead of 2.7 KB (about 1.6 MB an hour on a phone instead of 5.7 MB). SQLite under load gave "database is locked" on 29 % of joins; with WAL, IMMEDIATE and a 5 s busy timeout it gave 0 errors. Concurrency invariants (no double booking, no over-full group, no duplicate order or enrolment, balanced ledger) held after every load run on both engines.

Estimated host capacity: about 30 one-to-one lessons at once per CPU core (section 3.4 of the perf doc). Not measured on the host.

## 18. Test results

Final run by the coordinator on the integrated trunk `e4d7d64` (all waves, the polish wave and the fix round merged), 2026-11-13.

| Suite | Result | Where recorded |
|---|---|---|
| PHP, SQLite in memory | **1,034 tests, 1,027 pass, 7 skipped, 0 fail** (baseline 293 / 286 / 7). The 7 skips are Jetstream features turned off (registration, email verification, team invitations, 2FA settings) | coordinator run on `e4d7d64` |
| PHP, PostgreSQL 16 (`bin/test-pgsql.sh`, throwaway database) | **1,034 tests, 1,027 pass, 7 skipped, 0 fail** | coordinator run on `e4d7d64` |
| JavaScript (`node --test tests/js/*.test.mjs`) | **26 pass, 0 fail** | coordinator run on `e4d7d64` |
| Build and caches | `npm run build`, `php artisan view:cache` and `route:cache` pass | coordinator run on `e4d7d64` |
| Browser end to end (`node tests/e2e/run.mjs`) | **All 9 spec files pass**: 6 journeys with 53 evidenced steps, 9 failure scenarios, the two-browser classroom (16 checks), the route sweep (266 GET routes, 249 swept, 13 roles at 390 and 1440 px, 6,474 page loads, 0 problems; 17 skipped for want of a parameter value). No outbound internet attempt | coordinator run on `e4d7d64`; method in `docs/R47_QA.md` |
| Two-browser WebRTC | Peer connections "connected", video and audio both ways, chat, whiteboard, notes, attendance | E2E 08 |
| Accessibility (axe, real Chrome) | 910 page states, **0 serious or critical** (was 81 states, 1,746 nodes); keyboard 185 checks at 390 and 161 at 1440, 0 failed; reduced motion 22 checks, 0 failed; young step at AAA contrast, 0 violations | `docs/R47_A11Y.md` section 2 |
| Upgrade rehearsal on a populated pre-R-47 database | PASS on SQLite and PostgreSQL, 60+ checks each: no row lost, cents exact, subject ids kept | `docs/R47_OPERATIONS.md` section 8 |
| Signed-in production-like smoke (`tests/Support/signed-in-smoke.sh`) | PASS, 101 checks (coordinator run on `e4d7d64`) | `docs/R47_OPERATIONS.md` section 8 |
| Backup and restore drill | PASS on a fake host, both engines; fails when a lesson file is missing, as it should | same |
| Concurrency (forked processes) | Same slot, triple tap, last seat, enrolment, order key, four simultaneous webhooks: one winner each, both engines | `ConcurrencyInvariantsTest` |
| CI workflow | `actionlint` clean; each job run locally. **Not yet run on GitHub** | `docs/R47_OPERATIONS.md` section 8 |
| Deploy workflow | Run against a fake pre-R-47 host (deploy, second deploy, a preflight FAIL kept the site down, fix, recovery). **Not run against the real host** | same |

Bugs found by the browser suite and fixed with regression tests: 8 (one high: a 500 on the tutor's assessment page; one high: Grade 11 rooms showed no Grade 10 to 12 tutors). See `docs/R47_QA.md` section 6.

## 19. Remaining limitations

Collected from every domain document. Grouped, not repeated.

**Providers and devices never exercised for real**
- Paystack (payments, refunds, transfers), InfoDot itself, Anthropic, SMTP, Web Push to a real device, SMS, a TURN relay. All stubbed or faked.
- Chrome only. No Safari, no Firefox, no real phone, no screen reader. Both WebRTC peers ran on one machine.
- The scheduler was not run in real time in tests; specs moved timestamps.

**Not built (each is a recorded decision or owner item)**
- Live lessons: no TURN server, no SFU (rooms over 6 people refused), no recording, no whiteboard undo or images, no interactive exercises in the call.
- Room live chat; announcements for replies under discussions and for tutor-room workshops.
- Recurring subscriptions, saved cards, split payments, referral rewards, storefront view analytics.
- Reschedule as one action; a learner's own time zone.
- Resumable uploads, video transcoding and DRM, embedded external video, virus scanning.
- Automated register checks, document age verification, guardian identity checks, plagiarism and AI-text detection, image scanning.
- Course report button; minor selling a priced library resource not caught at upload.
- AI streaming, lesson-summary drafting, maths rendering.
- Commission rules, platform coupons and credits are created through the finance API, not a screen. No course review queue. Support threads cannot be closed.
- Dot.Files, Finance, Agents, Brain, Memory, Pulse not called.

**Content and language**
- Interface strings in South African languages other than English and Afrikaans: 0 %. Afrikaans, French, Portuguese are AI-drafted and unreviewed, for academic strings only.
- Grade R, Second Additional Language, smaller FET electives, TVET and schools not seeded. Topics for a few subjects only. Names unreviewed.
- Young-step copy still above Grade 3 reading level on the courses page, storefronts and room rules; the inbox and safety pages do not adapt to the young step (D-1709).

**Accessibility, recorded not fixed**: duplicate landmark names on `/teach/students`, `/money/earnings`, `/money/ops`; no h1 on the legacy Jetstream `/user/profile`.

**Engineering**: Larastan not in CI; repeated safeguarding reads per request (a request-wide cache is a safety decision); matching scores at most 300 candidates.

## 20. Production risks

| Risk | Why it matters | Mitigation in place | Owner action |
|---|---|---|---|
| Mail is `log` on the host | Guardian requests, receipts and safety alerts reach nobody; a guardian request lapses in 72 h | Settings page says "not delivered"; preflight warns | Configure SMTP (step 9) |
| No TURN relay | Learners on mobile data and school networks may fail to connect | Honest message and the tutor's own meeting link | Rent a TURN server (step 15) |
| Shared host polling capacity | About 30 one-to-one lessons per core is an estimate | Lean polls, back-off, hidden-tab slowdown | Measure on a staging copy; consider `CACHE_STORE=file` |
| SQLite on the host | One writer at a time while classrooms poll | WAL, IMMEDIATE, busy timeout from env | Move to PostgreSQL before real traffic |
| Unknown-age default `ask` | Existing live users without a birth month are asked before they book, pay, message or meet | Intended protection | Tell users; do not switch to `adult` except as a recorded exception |
| Pre-R-47 tutors not bookable for paid lessons | They need an offering and open hours | Preflight counts bookable tutors | Tell tutors (step 13) |
| Legal positions unreviewed | Consent, recording, cooling-off, screening duty, cross-border AI processing for minors | Nothing claimed | Legal review (step 19) |
| Moderation capacity | Posts by under-13s wait for a person; incidents escalate every 5 minutes | Queues and timers | Name two safeguarding officers; daily checks |
| Screening is manual | A tutor is only as screened as the operator's record | Four eyes and evidence | Decide the process and the reviewers |
| Pipeline never run for real | CI not yet run on GitHub; deploy not run on the host | Rehearsed on a fake host; restore point before maintenance mode | Watch the first run; read the log |
| Backups not installed | Only per-deploy restore points exist until the cron line is added | Scripts ready and tested | Install daily backup and run the drill (step 17) |
| CSP escape hatch left on | The host `.env` may still say `SECURITY_CSP_REPORT_ONLY=true` | Enforced by default in code | Set `false` (step 2) |
| Domain renewal | The owner's backlog records `infodot.co.za` expiring on 27 November 2026; `tutor.infodot.co.za` and InfoDot sign-in depend on it | None in this app | Renew the domain |

## 21. Recommended next improvements

In order of value to learners and tutors:

1. Merge and verify the learner and tutor polish branches against the jury findings (section 27).
2. Run one real Paystack test-mode journey, then go live (steps 10 and 11).
3. Configure SMTP and a TURN relay; test a lesson between two real phones on different networks, including Safari on iPhone.
4. Commission isiZulu, isiXhosa, Sepedi, Setswana and Sesotho interface translations, review Afrikaans, and key the remaining hard-coded screens.
5. Legal review, then publish terms and a privacy notice that match the product (the jury found the current terms still describe "a tutoring session marketplace" and name a different operator from the footer).
6. A screen-reader session (VoiceOver, TalkBack) and real-device checks.
7. Move the host to PostgreSQL and measure capacity on a staging copy.
8. Reschedule as one action; a learner time zone; resumable uploads.
9. Recurring lesson subscriptions with a guardian mandate (D-511).
10. Lesson-summary drafting and maths rendering for the AI assistant, once the key and budget are set.
11. Larastan in CI; a request-wide cache of safeguarding facts if the safety owner agrees.
12. Revisit Dot.Files, Finance and Brain when they publish documented, consented APIs.

## 22. Implemented, partial and planned, by brief area

| Brief section | Status | One line of evidence |
|---|---|---|
| 2. Audit | Implemented | `docs/R47_AUDIT.md`, 30 areas plus 19 domain and wave audits |
| 3. Research and positioning | Partial | `docs/R47_RESEARCH.md`; legal sources verified, competitor facts mostly unverified |
| 4. Design and engineering skills | Partial | `docs/R47_SKILLS.md`; two requested skills unavailable; no motion skill applied; Boost MCP use not recorded |
| 5. Academic learning rooms | Implemented | `tests/Feature/Community/*`, E2E 04 |
| 6. Discovery and matching | Implemented | `MatchingTest`, E2E 01 steps 5 to 7, E2E 05 |
| 7. Tutor entrepreneurship | Partial | Lessons, groups, courses, materials, packages, exam prep, paid workshops built; subscriptions not built; "structured programmes" covered only by courses |
| 8. Course marketplace | Implemented | E2E 03, E2E F5, `tests/Feature/Learning/*` |
| 9. Study material library | Implemented | learning tests, E2E 04 step 4, E2E F6; no virus scanner |
| 10. Multilingual | Partial | E2E 05; interface strings English only in practice; machine translation only against a stub |
| 11. Rooms, study groups, collaboration | Partial | Rooms and Q&A by E2E 04; study groups by feature tests only; no live chat |
| 12. Live tutoring and classrooms | Partial | E2E 08 two-browser WebRTC; no TURN, no recording, cap 6, Chrome only |
| 13. Assessments and progress | Implemented | learning tests, E2E 01 step 12, E2E F9 |
| 14. AI learning assistant | Partial | `tests/Feature/Ai/*` and E2E 05 against a stub; off until a key is set |
| 15. Integrity, child safety, trust | Implemented (enforcement); Partial (verification) | `tests/Feature/Safety/*`, `ChildSafetyBypassTest`, E2E 06, F2, F7, F8; screening and age self-declared or operator-recorded |
| 16. Tutor verification | Implemented | `ProfileVerificationTest`, E2E 02 step 5b |
| 17. Payments, commission, earnings | Partial | Ledger, commission, earnings, payouts by E2E 02 and 03; Paystack only against stubs; subscriptions not built |
| 18. Tutor marketplace growth | Partial | Storefront, referral link, promo page, insights by `TutorWorkspaceTest`; no referral rewards or view analytics |
| 19. Notifications, scheduling, communication | Partial | In-app by `tests/Feature/Comms/*` and E2E 04 step 5; email by fake and SMTP stub; push never on a device; SMS adapter only |
| 20. Admin command centre | Implemented | `tests/Feature/Ops/*`, `OperatorRouteMatrixTest`, used by E2E 02, 04, 06 |
| 21. Dot ecosystem | Partial | InfoDot implemented; Notify and Analytics off until configured; the rest not integrated (D-916) |
| 22. Backend architecture | Implemented | `docs/R47_ARCHITECTURE.md`, suites on two engines, `RouteAuthorizationMapTest` |
| 23. Accessibility, mobile, performance | Partial | `docs/R47_A11Y.md`, `docs/R47_PERF.md`; no screen reader, real phone, Safari or Firefox; no resumable uploads |
| 24. Roadmap phases 1 to 8 | Partial | Phases 1 to 7 built in waves; phase 8 tested but not deployed |
| 25. Acceptance tests | Implemented (against stubs) | `tests/Feature/Acceptance/*`, E2E 01 to 07 |

---

## 23. Definition of done (brief section 26)

| # | Item | Met? | Evidence or gap |
|---|---|---|---|
| 1 | Existing app audited | Yes | Section 1 |
| 2 | Student and tutor experiences intuitive | Partly | Jury round 1: 5.95 and 6.1 of 10; polish wave in progress (section 27) |
| 3 | Grade 1 to postgraduate | Yes (data); journeys at Grades 5, 10, 11 and adult | No postgraduate journey driven |
| 4 | Rooms functional and organised | Yes | E2E 04 |
| 5 | Discovery and matching end to end | Yes | E2E 01, 05 |
| 6 | Professional tutor profiles | Yes | E2E 02 |
| 7 | Tutors manage bookings and earnings | Yes | E2E 02 |
| 8 | Tutors create and sell courses | Yes | E2E 03 |
| 9 | Students access purchased courses | Yes | E2E 03 step 7, F5 |
| 10 | Upload and discover resources | Yes | E2E 04 step 4, 05 step 4 |
| 11 | Resource ownership protected | Yes | E2E F6, learning tests |
| 12 | Multilingual beyond labels | Partly | Teaching-language matching and translation provenance yes; interface English in practice |
| 13 | Language-aware discovery | Yes | E2E 05 |
| 14 | Communities functional and moderated | Yes | E2E 04 step 7 |
| 15 | Payments and payouts use real integrations | Partly | Real Paystack code; proven against stubs only; payouts by manual bank file |
| 16 | Child safety enforced | Yes, server-side | E2E 06, F7, F8 |
| 17 | AI through genuine integrations | Partly | Real SDK path; off by default; stub only |
| 18 | InfoDot authentication correct | Yes (against a stub of InfoDot) | E2E 01, 02, 06; S-01 fixed |
| 19 | Mobile and accessibility tested | Partly | axe, keyboard, 390 px sweeps; no screen reader or real phone |
| 20 | Critical automated tests pass | Yes | Section 18 |
| 21 | No known critical security, payment or data-integrity issue | Yes, as far as tested | 27 security findings fixed; accepted risks listed |
| 22 | Nothing presented as complete when it is a mockup | Yes | Off states are said in words on every screen (payments off, AI not configured, not recorded, email not delivered) |

## 24. External services: real, off until configured, not integrated

| Service | State on first deploy | Switch |
|---|---|---|
| InfoDot SSO | Real, needs the shared secret and `tutor` marked live in InfoDot | `INFODOT_SERVICE_SECRET` = InfoDot's `ECOSYSTEM_SECRET_TUTOR` |
| Paystack | Off until keys are set; test mode first; live needs a second switch | `PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_ALLOW_LIVE=true` |
| Paystack Transfers | Off | `MONEY_PAYSTACK_TRANSFERS=true` (Registered Business, funded balance) |
| Anthropic (AI assistant, machine translation, AI need rewording) | Off | `AI_ENABLED=true`, `ANTHROPIC_API_KEY`, `AI_MONTHLY_BUDGET_USD`, optional `AI_MODEL_LIGHT` |
| SMTP mail | Off (`MAIL_MAILER=log`): nothing is delivered | `MAIL_MAILER=smtp` and the `MAIL_*` lines |
| Web Push | Off | `php artisan comms:vapid-keys`, paste into `.env` |
| TURN relay | None exists | `CLASSROOM_TURN_URLS` and `CLASSROOM_TURN_SECRET` (or username and credential) |
| STUN | Google's public STUN by default (sees participants' network addresses, never media) | `CLASSROOM_STUN_URLS` (own server) or `none` |
| Dot.Notify | Off | `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET`, plus `tutor.*` entries in Notify's catalogue |
| Dot.Analytics | Off (pre-R-47 snapshot) | `ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET` |
| SMS | Adapter only, no provider | Not built |
| Dot.Agents, Brain, Memory | Not integrated (D-916) | None |
| Dot.Files, Finance, Pulse | Not integrated | None |

`php artisan tutor:preflight` reads all of these and prints PASS, INFO, OFF, WARN or FAIL. Its last line, "Can a learner book a paid lesson?", names what still blocks a paid booking.

## 25. Build summary

- 113 commits on the trunk between 2026-11-10 and 2026-11-12; 1,294 files changed against the last `main` (`2aa8daf`).
- Waves: 1 (design system, academic, marketplace, safety, money, learning), 2 (community, classroom, ai, comms, student UI, tutor UI, ops and guardian UI, integration), 3 (deploy, security, QA, perf and accessibility), then polish.
- 418 decisions recorded in `docs/R47_DECISIONS.md`.
- Owner runbook: `docs/R47_OPERATIONS.md`. Personal information record: `docs/R47_PRIVACY.md`.

## 26. Jury

A two-critic jury scored screenshots of every audience on 2026-11-12 (cards in the session scratchpad, `tutor-jury/critic-A.md` and `critic-B.md`). Weighted score: 0.4 design, 0.3 usability, 0.2 creativity, 0.1 content.

| Critic | Scope | Design | Usability | Creativity | Content | Weighted |
|---|---|---|---|---|---|---|
| A | Public, learner, young learner, guardian | 6 | 5.5 | 6.5 | 6 | **5.95** |
| B | Public, tutor workspace, classroom, operations | 6 | 6 | 6.5 | 6 | **6.1** |

What both praised: the public pages' level "shelf" idea, the explained-match receipt, specific local copy, honest empty and off states, strong safeguarding urgency in operations, 0 axe violations on public pages, light pages (LCP under 1.9 s on a throttled phone).

What they marked down, by theme:
- **Guardian** (critic A, 4 to 5): the safety page repeated the home page; an urgent child-safety report sat in a side card beside a green "Active" badge; declined requests vanished; parents got the learner navigation.
- **Learner and young learner**: a wall of slot buttons on the booking page; every match card repeating the same reasons; a two-hour time-zone difference between an order and its notification; adult wording on the young step; the team switcher shown to learners and parents.
- **Tutor and operations**: phone tables hiding money and actions; two shells for one tutor; three wordings of one verification status; legacy copy saying payment happens off-platform; earnings rows naming the tutor; developer words ("php artisan academic:sync", "user:60").
- **Classroom**: "Not joined" beside "The tutor ended this lesson"; learners told about relay servers; Join far down the phone screen.
- **Money figures**: a slashed zero read as "R25Ø.ØØ"; four money formats side by side.
- **Public**: no meta descriptions, canonical links, sitemap or Open Graph tags; legal pages in a different style that still describe "a tutoring session marketplace" and name the operator differently from the footer.

A polish wave was started to address these (next section).

## 27. Polish wave and round 2

Three polish agents worked the two critics' fix lists in parallel, each owning separate files. All three are merged (trunk `2b3e2d8`); final counts are in section 18. Each fix, with the file or the reason it was not done, is in the agent's own doc.

| Branch | Doc and decisions | What changed |
|---|---|---|
| `feat/r47-polish-guardian` | `docs/R47_POLISH_GUARDIAN.md`, D-2100 to D-2108 | Guardians get their own navigation and phone bar (Children, Approvals, Safety, Messages, Payments). `/safety/guardian` is a real Safety page: one row per report, case or notice about their own children, with status, next step and the 10111 and Childline numbers, never the report text or the reporter. An open urgent report is a red band at the top and the child's badge reads "1 urgent report". Approvals keep a "Recent decisions" list. One money format (`Money::format()`, "R1 250.00"). Digits come from a plain-zero face, so R250.00 no longer reads "R25Ø.ØØ". Classroom: an ended lesson shows "Ended", no relay or TURN words for learners, Join directly under the preview on a phone |
| `feat/r47-polish-learner` | `docs/R47_POLISH_LEARNER.md`, D-2000 to D-2028 | **Bug fixed:** an order line stored a UTC time (08:00) while the notification said 10:00 SAST; times now use the reader's zone and say which zone. **Public discovery:** `/find-a-tutor`, `/t/{id}` and `/u/{handle}` work signed out, approved tutors only, contact details hidden, booking and reporting still need sign-in, guest search rate-limited. Unique titles and descriptions, canonical links, Open Graph and Twitter tags, a self-hosted share image, `sitemap.xml`, `robots.txt`. Legal pages restyled and rewritten to match the product (a lawyer must review). Useful 404. Booking as a day strip then that day's times (13,113 px to 2,249 px on a phone). Match cards state shared reasons once. Young-learner copy for a 10-year-old. Team switcher only for people in more than one team |
| `feat/r47-polish-teach` | `docs/R47_POLISH_TEACH.md`, D-2200 to D-2214 | One stacked row-card pattern for tutor and ops tables on phones (key figure first, action always visible). Tabs that scroll with the active one in view. One tutor shell: `/tutor/profile` and `/tutor/availability` forward to the workspace and the calendar is the only hours editor. One status component (Listed, qualification, cleared for under-18s) worded the same for tutors and operators. "Payment is arranged outside the platform" and the duplicate hourly rate removed. Searchable grouped subject picker; grouped level checkboxes that work on touch. Earnings rows name the learner and what was sold. Materials default to "All rights reserved". Plain operator language, integer chart ticks, a compact team page. Also fixed: after any upload the next page loaded without its script (D-2214) |

Left open from the critics' lists (each recorded in the polish docs):
- Guardians have no "Message [child]" button: safety refuses direct adult and minor conversations, so the button would do nothing.
- Guests cannot file reports; young learners have no after-school-only slot filter.
- Assigning roles to several operators at once needs a bulk endpoint; it is one person at a time.
- Order lines saved before the time-zone fix still show the UTC time; correcting old rows is an owner decision.
- Legal text needs a lawyer's review before launch.

**Round 2 jury.** A fresh capture of the polished trunk (75 screens at 1440 and 390 px, made from the E2E journeys' own data) went to two new critics who never saw round 1. Cards: session scratchpad `tutor-jury-r2/critic-A.md` and `critic-B.md`.

| Critic | Scope | Round 1 | Round 2 | Biggest moves |
|---|---|---|---|---|
| A | Public, learner, young learner, guardian | 5.95 | **6.39** | Guardian usability 4 to 7, SEO 5 to 8, Markup 4 to 8, Accessibility 7 to 8 |
| B | Public, tutor workspace, classroom, operations | 6.1 | **6.1** | Tutor usability 6 to 7, SEO 5 to 8, Markup 4 to 8, Accessibility 6.5 to 8, Responsive 5 to 7 |

The public-page audit went from 7 FAIL and 44 WARN to 0 and 0.

**Fix round after round 2.** Both critics independently found a few real defects, which two agents fixed before release (`docs/R47_FIXES.md`, D-2300 to D-2312; `docs/R47_POLISH_TEACH.md`, D-2400 to D-2408):
- Public pages had no menu on phones: there is now an accessible Menu button.
- The sign-in page offered only email and password: "Continue with InfoDot" now links to InfoDot's real `/go/tutor` launcher. It shows only once `INFODOT_LAUNCH_URL` and the service secret are set (owner step 8).
- A storefront said "Not yet verified" above "Verified by Dot.Tutor": one truthful state everywhere, including "Qualification checked, level pending", and operators are prompted to set the level.
- The old finance desk showed UTC as local time (21:33 against 23:33): `/money/ops` now forwards to the operations payments screens, and every operations time uses the reader's zone.
- Booking ignored the hours the learner asked for: the search window carries into booking, times are grouped Morning / Afternoon / Evening, and young learners default to after school.
- Other fixes:
  - Notifications marked every row unread.
  - Receipts cut off the paid column on phones.
  - Guardian deadlines still read as promises after they had passed.
  - The incident page lacked the Hide and Suspend actions its queue told officers to take.
  - Tutor workspace pages rendered at two sizes.
  - The operations rail was clipped at 900 px.
  - Classroom opening times had no date.
  - The audit history showed raw IDs.

These fixes have not been scored by a third jury.

## 28. Deployment

Released 2026-11-12 (23:46 UTC, 2026-11-13 01:46 SAST).

| Item | Result |
|---|---|
| Pull request | [sakhilebhayi/Dot.Tutor#27](https://github.com/sakhilebhayi/Dot.Tutor/pull/27), squash-merged into `main` as `b4ee003`. All five CI jobs green on the final head (PHP on SQLite and PostgreSQL 16, JS, upgrade rehearsal, signed-in smoke). GitGuardian's 11 hits were false positives: the throwaway PostgreSQL service password in CI and the RFC 8291 test vector |
| Mode | `confirm=deploy`. A read-only drift report first showed the host was a git checkout at `2aa8daf` with all 343 tracked files identical to `main`, so no takeover was needed |
| Deploy run | [43330996771](https://github.com/sakhilebhayi/Dot.Tutor/actions/runs/43330996771): success. Its own CI gate re-ran all five jobs green before touching the host |
| Release | `2aa8daf` to `b4ee003` |
| Rollback copy | `~/dot-tutor-deploy-20261112T234541Z.tgz` (1.6 MB) |
| Database restore point | `~/dot-tutor-db-deploy-20261112T234541Z.sqlite` (284 KB), integrity ok. The live database is SQLite |
| Maintenance window | 23:45:49 to 23:46:38 UTC (49 s) |
| Migrations | 26 applied (45 in total, none pending). All additive |
| `academic:sync` | 15 languages, 1 country, 4 curricula, 45 levels, 71 subjects, 882 subject offerings; every pre-R-47 subject linked |
| `tutor:preflight` | **OK: 39 pass, 5 info, 5 off, 7 warnings, 0 failures.** The CSP is enforced, debug is off, the session cookie is Secure, HSTS is on, caches are built and 14 scheduled tasks are registered with cron seen running |
| Cron | Installed through the shared crontab under the flock, other apps' lines unchanged |
| Live smoke (runner) | Passed: pages, guest redirects, security headers, built assets, fonts, icons, the service worker, private storage and `.env` not served, no debug page |
| Independent check (coordinator) | `/`, `/login`, `/find-a-tutor`, the legal pages, `sitemap.xml`, `robots.txt` and `/up` answer 200; `/learn`, `/teach`, `/ops` and `/guardian` redirect a guest to sign-in; a missing page is a real 404. Unique title, description and Open Graph tags. In a browser at phone width: the landing page, public tutor search and sign-in render with the phone menu and **no console errors or CSP violations** |

**Preflight warnings, all owner items (section 29):**
- Mail is `log`, so nobody receives email (step 9).
- The database is SQLite: fine for launch, move to PostgreSQL before real traffic (step 3).
- Paystack is off: free lessons and courses work, and paid items say online payment is not enabled (steps 10 and 11).
- There is no TURN relay: some phone and school networks cannot connect, and the classroom offers the tutor's own meeting link (step 15).
- **There are no operators and no safeguarding contacts yet**: sign in through InfoDot, then `php artisan ops:grant <email> --role=admin`, and name safeguarding officers (step 7).
- "Can a learner book a paid lesson?": **NO**, because there is no Paystack key and no approved tutor with a priced offering and open hours (steps 10 to 13).

Also off until configured: the AI assistant, Web Push, SMS, Dot.Notify and payout transfers. InfoDot sign-in has its service secret set. Dot.Analytics ingest is on.

Nothing kept the site in maintenance mode. The `infodot.co.za` domain renewal (step 1) is still the first owner item: this subdomain and every sign-in depend on it.

## 29. Owner steps

Do them in this order. Each `.env` change is followed by `php artisan config:cache` and `php artisan tutor:preflight` (`docs/R47_OPERATIONS.md`, the two rules at the top).

### Before or at the deploy

1. **Renew `infodot.co.za`** if not already done (your backlog lists 27 November 2026). Sign-in and this subdomain depend on it.
2. **Host `.env` security lines.** Set `SECURITY_CSP_REPORT_ONLY=false` (or remove the line), `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning`, `BROADCAST_CONNECTION=log`, `APP_DEBUG=false`. Preflight warns on each one still wrong.
3. **If the host database is SQLite:** set `DB_JOURNAL_MODE=wal`, `DB_TRANSACTION_MODE=immediate` and `CACHE_STORE=file`. Plan the move to PostgreSQL before real traffic (`docs/R47_OPERATIONS.md` section 6).
4. **Academic reference data after every migrate.** The deploy runs `php artisan academic:sync` after `migrate`. If you ever migrate by hand, run `php artisan academic:sync` yourself straight after. It is idempotent and never overwrites your edits.
5. **Compression rules if the docroot is separate.** If the site's document root is not `<app>/public`, copy the gzip and cache block at the end of `public/.htaccess` into the docroot's `.htaccess`. The deploy does not sync that file. Without it phones download two to three times the bytes.

### Right after the first deploy

6. **Read the deploy log** for `== Database restore point`, the `academic:sync` lines, `Preflight OK` and `== Smoke check passed.` Then run `php artisan tutor:preflight` and read every WARN.
7. **Become the operator.** Sign in once through InfoDot, then run `php artisan ops:grant <your email> --role=admin`. Give every other operator the narrowest role on the team page. Name at least two safeguarding officers (screening needs four eyes), or set `SAFETY_SCREENING_FOUR_EYES=false` and record why.
8. **InfoDot SSO secrets.** `INFODOT_SERVICE_SECRET` in this app must equal `ECOSYSTEM_SECRET_TUTOR` in InfoDot's `.env`, and `tutor` must be marked live in InfoDot's registry. Until then nobody can sign in. Also set `INFODOT_LAUNCH_URL=https://infodot.co.za/go/tutor` so the sign-in page shows "Continue with InfoDot" (hidden until it is set).
9. **Mail (SMTP).** `MAIL_MAILER=smtp`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS=no-reply@infodot.co.za`. Test by inviting yourself as a guardian from a test learner account.
10. **Paystack, test mode.** Set the webhook URL `https://tutor.infodot.co.za/api/tutor/money/webhooks/paystack` in the Paystack dashboard. Put `PAYSTACK_SECRET_KEY=sk_test_...` and `PAYSTACK_PUBLIC_KEY=pk_test_...` in the host `.env`. Run one whole paid journey with test cards, and one for a minor (it waits for the guardian, then the payment waits for the guardian).
11. **Money settings, then live.** Decide `MONEY_COMMISSION_PERCENT` (15 is a placeholder), refund windows, the lesson hold, payout day and minimum, and whether tutors carry Paystack's fee. Fill in `MONEY_OPERATOR_LEGAL_NAME`, `MONEY_OPERATOR_REGISTRATION`, `MONEY_OPERATOR_ADDRESS`, `MONEY_SUPPORT_EMAIL`, and VAT only if registered. Then set the live keys **and** `PAYSTACK_ALLOW_LIVE=true`. Leave `MONEY_PAYSTACK_TRANSFERS=false` unless you have a Registered Business with transfers enabled.
12. **Decide the earnings hold against the dispute window** (24 h hold, 7-day disputes; security doc section 2) and keep `SAFETY_UNKNOWN_AGE_POLICY=ask`.
13. **Existing tutors.** Tutors approved before R-47 stay approved but **cannot be booked for a paid lesson until they create at least one offering and set open hours**. Their old qualifications show as "Self-declared, not verified". Email them. Then work `/operator/verification` and record screening for anyone who teaches minors in `/safety/operator/screening`.
14. **Anthropic (optional).** Only after step 21's decision on minors' data: set a spending limit in the Anthropic console, then `AI_ENABLED=true`, `ANTHROPIC_API_KEY`, `AI_MONTHLY_BUDGET_USD`, and decide `AI_MODEL_LIGHT` (Haiku 5.5 cuts translation cost about 40 times). Check Anthropic's data-retention settings and usage-policy requirements for products used by minors.
15. **TURN relay.** Rent a managed TURN service or coturn on a small VPS. Set `CLASSROOM_TURN_URLS` and `CLASSROOM_TURN_SECRET` (or `CLASSROOM_TURN_USERNAME` and `CLASSROOM_TURN_CREDENTIAL`). Optionally point `CLASSROOM_STUN_URLS` at it to stop using Google's STUN.
16. **Web Push.** `php artisan comms:vapid-keys`, paste the lines into the host `.env`, never change them later. Optional: Dot.Notify (`NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET`, add `tutor.*` to Notify's catalogue, no Notify email rules for `tutor.*`) and Dot.Analytics (`ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`).
17. **Backups on day one.** Run `scripts/ops/backup.sh` once by hand, then add the cron line with the marker `# tutor-backup` under the shared crontab lock (exact command in `docs/R47_OPERATIONS.md` section 2). Set `BACKUP_OFFSITE` or fetch the folder weekly. Run `scripts/ops/restore-drill.sh` now and monthly. Add an uptime monitor on `/up`. Keep a copy of `.env` (with `APP_KEY`) in a password manager.
18. **Safeguarding operations.** Fill in the escalation table (`docs/R47_OPERATIONS.md` section 4) and print it. Someone checks `/safety/operator/incidents`, the report queue and `/rooms/moderation` every working day (posts by under-13s wait for a person). Add a reporting route for suspected child sexual abuse material (FPB hotline 0800 424 247, SAPS) to your runbook.

### Reviews by people (before you promote the platform)

19. **Legal review** of the terms of service, privacy notice and cookie notice (rewritten in the polish wave to describe only what the product does, but not reviewed by a lawyer; confirm the operator's legal name), the guardian consent text, the recording consent, the academic integrity policy, the CPA and ECT cooling-off position for lesson packages, and the twelve questions in `docs/R47_RESEARCH.md`.
20. **Native-speaker review** of the Afrikaans strings (AI-drafted), and commission isiZulu, isiXhosa, Sepedi, Setswana and Sesotho interface translations first. Record each review in `lang/locales.php`.
21. **POPIA items** from `docs/R47_PRIVACY.md` section 6: name an information officer and write the PAIA manual; publish a privacy notice that matches the personal-information record (including that backups keep erased data for 14 days); sign or record processor agreements with Paystack, Anthropic, InfoDot hosting and the Google STUN choice; decide the POPIA basis for sending minors' questions to a US processor before switching AI on; set retention periods for money records (SARS, 5 years after the tax year) and safeguarding records; decide the route and response time for safeguarding-record access requests; decide on guardian identity verification; write a breach-notification procedure (s22).
22. **Academic editor review** of the seeded curricula, levels, 71 subjects, about 880 offerings, topics and the IEB "Advanced Programme Mathematics" name in `/ops/academic`. Switch off what you do not serve; add Grade R or other electives if needed.
23. **A teacher's read of the young-learner screens** (the reading-level check is a heuristic).

### Last check

24. `php artisan tutor:preflight` shows `Can a learner book a paid lesson?  PASS`. Then do one complete journey on real phones: a learner finds a tutor in their language, books, pays, attends in the classroom and reviews; the tutor sees the earning; a guardian approves a child's booking.

### Local incident to revert (owner's own machine)

25. During the build a local working copy's `.env` pointed at the owner's local PostgreSQL database `infodot`; a migration created two empty tables `cache` and `cache_locks` and migration rows id 28 and 29 (batch 3) there, then failed and rolled back. No InfoDot data was read or changed. To revert:

    ```
    psql -U postgres -d infodot -c "drop table cache, cache_locks; delete from migrations where id in (28,29) and batch=3;"
    ```

    Check first that ids 28 and 29 in batch 3 are the `cache` migrations (`select id, migration, batch from migrations where id in (28,29);`) before you run the delete.
