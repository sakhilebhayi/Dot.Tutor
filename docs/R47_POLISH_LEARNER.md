# R-47 polish: learner, young learner and public pages (polish-learner)

Branch `feat/r47-polish-learner`, decisions D-2000 to D-2025, port 8740. Source: critic cards `critic-A.md` (public, learner, young) and `critic-B.md` (public items) in the jury folder. Guardian screens and the money numeral font belong to polish-guardian; tutor, ops and classroom screens to polish-teach.

## 1. The time-zone bug (owned item 1)

**Root cause.** `BookingPurchasable::purchaseTitle()` (and the seat purchasable) formatted `starts_at` with no zone, so the order line title, frozen when the order is priced, held the UTC wall clock: "15 Nov 2026 08:00". The notification used comms' `Messenger::when()`, which converts to the reader's zone: "Sun 15 Nov at 10:00 SAST". Two smaller copies of the same fault: booking hold expiries printed in UTC, and the classroom guardian-approval summary used `app.timezone` (UTC).

**Fix, once.** `Times::zone()` now resolves the reader's zone through `Student\Support\ViewerZones`, which asks comms' `QuietHours::timezoneFor()` (the one place a person's zone was already decided for messages: notification setting, then tutor profile, then `tutor.timezone`) once per request. `Times::stamp()` names the zone on a time that travels alone (order paid, receipt date, purchases list, hold expiry, inbox tooltip, assistant answer, edit history). Frozen titles carry the platform zone with its name ("15 Nov 2026 10:00 SAST"). Test: `tests/Feature/Student/LearnerTimesTest.php` (the order line, the checkout page and the notification agree; a reader in Europe/London reads 08:00 GMT; a guest reads SAST).

**Owner note.** Order lines written before this change keep their UTC text; money records are not rewritten. If production already holds paid lesson orders, a one-off correction of their titles is an owner decision.

## 2. Critic fixes owned here

| Critic item | What changed | Where |
|---|---|---|
| A 5, time zones | Section 1 | `app/Domain/Student/Support/{Times,ViewerZones}.php`, purchasables, `BookingPresenter` |
| A 25, B 20, MANIFEST: Find a tutor and storefronts redirect guests | `/find-a-tutor`, `/t/{id}`, `/u/{handle}` (via the redirect), the tutor photo and the find redirect are public; approved tutors only; contact details hidden from guests (headline, introduction, offering text through `view_contact`); booking, reporting and the plain-words reader need sign-in and the booking button returns the guest to the booking page after sign-in; guest searches limited per address | `routes/tutor/marketplace.php`, `routes/tutor/student.php`, `x-student.shell` (public shell for guests), `TutorStudentServiceProvider` (limiters), `FindComposer`, `TutorPresenter`, `PublicDiscoveryTest`, `RouteAuthorizationMapTest` PUBLIC list |
| A 10, B 6: meta | Unique titles (sign-in pages through `x-guest-layout title`), descriptions, canonical, Open Graph and Twitter cards, self-hosted card image, `sitemap.xml`, robots.txt | `layouts/partials/head`, `GuestLayout`, `SitemapController`, `routes/tutor/public.php`, `public/images/og-card.png`, `public/robots.txt`, `PublicMetaTest` |
| A 24 audit, B 18: skip link on /login, /forgot-password, 404 | The skip link was present but `autofocus` on the email field jumped past it; autofocus removed on both pages; error pages gained the skip link. Header "Sign in" hidden on /login; submit says "Sign in" | `auth/login`, `auth/forgot-password`, `errors/_ecosystem-layout`, `public-top` |
| A 19, B 5: legal pages | Public shell, display face, square surface panel, a Terms/Privacy/Cookies switcher; text rewritten to what the product does; no dashes | `terms`, `policy`, `cookies` views, `partials/legal`, `resources/markdown/*.md` |
| A 22, B 19: 404 | This site's routes instead of 24 Dot platforms; full public header and footer | `errors/404`, `errors/_ecosystem-layout` |
| A 20, B 17: hover colour only | Underline draws in on every button label, end arrow steps 3 px, wordmark lifts | `resources/css/ds/components.css`, `shells.css` |
| A 3: slot wall | Day strip plus one day's times; one submit beside the price, sticky on wide screens. Phone height 13,113 px to 2,249 px (measured on the demo world) | `student/book`, `polish.css`, E2E helpers |
| A 4: repeated reasons | Shared reasons once above the cards ("All 13 tutors"), each card its own | `student/find/index` |
| A 11: empty-looking collapsed cards | Chevron, title and a one-line description of what is inside (find filters, library "Share material", resource "Report or suggest a change") | `.st-disclosure` |
| A 9: Report link on three lines | One summary line (chevron, flag, label); guests are told to sign in | `student/partials/report` |
| A 18: duplicate buttons | Storefront "Book" removed (a phone-only jump to "Lessons and prices" remains), lessons empty-state button removed, result page keeps "Back to the assessment" | views named |
| A 21: home ignores the finished lesson | "Your last lesson" with the tutor's published summary and "Book again" | `LearnerAgenda::lastLesson`, `student/home` |
| A 14: checkout | "What you bought" when paid, caption hidden, sums right-aligned | `student/money/checkout` |
| A 15: jargon | "Follows CAPS, your curriculum."; curriculum column shows CAPS; "PDF, 1 KB" | `AcademicScopeAdapter`, `MatchingService`, `FileKind` |
| A 23: flashcards | Two fields per card, "Add another card" (JS), old format still read | `student/practice`, `PracticeController`, `resources/js/student/cards-editor.js`, `tests/js/cards-editor.test.mjs` |
| A 24: green Open buttons | The title opens the notification; no row buttons except calendar and mark-read | `comms/inbox` |
| A 13: team switcher | Only for a tutor or operator with more than one team | `layouts/partials/frame` |
| A 8, 16 (young) | No AI-rewording link or "Not AI-generated" label, the child's grade in the search hint, no commission paragraph, plain price line, "Talk to your parent or guardian before you cancel", a quiet first cancel step, "Asked your parent or guardian" | `student/home`, `student/book`, `student/lesson` |

## 3. Not done, and why

- **"Further Education and Training Phase" pill** (A 15, learner/16): now "FET, Grades 10 to 12" in the room views (D-2026); not checked in the browser.
- **Young booking limited to after-school slots** (A 3, young): not added; the day strip already shows one day. Filtering by the learner's saved window would hide times a child may want at weekends without a product decision.
- **Status words shared with the guardian** ("Requested" vs "Waiting for the tutor", A 16): guardian screens are polish-guardian's.
- **Slashed-zero numerals and one currency format** (A 12, B 8): polish-guardian (global money font).
- **Account page "How screens look"** (A 17): done (D-2027). **Progress "Other" heading** (A 26): done (D-2028).
- **Guest reports**: a guest is asked to sign in to report a public storefront; anonymous reports would need a safety decision on abuse handling.
- **Legal review**: the terms, privacy policy and cookie policy now describe the product as it is, but **a lawyer must review them** before they are relied on, including the operator's name (BluePin Inc in the policies, "an InfoDot ecosystem platform" in the footer; the owner's records also spell the holding company "BluPin"). Nothing new was promised; the information officer, processors and retention periods remain owner items (`docs/R47_PRIVACY.md` section 6).
- **Deploy**: `public/robots.txt` and `public/images/og-card.png` reach the docroot through the deploy's public-file copy; `sitemap.xml` is a route (no static file of that name exists, so nothing shadows it).

## 4. Checks

- PHP: baseline 980 (973 pass, 7 skipped); after this work 996 (989 pass, 7 skipped); after merging the trunk with polish-guardian, 1009 (1002 pass, 7 skipped); new tests `LearnerTimesTest` (2), `PublicDiscoveryTest` (3), `PublicMetaTest` (4), `LearnerPolishTest` (7); updated with reasons: `MatchingTest` (curriculum wording), `ResponsiveShellTest` (team switcher), `EcosystemErrorPagesTest` (404), `RouteAuthorizationMapTest` (public list).
- JS: `tests/js/cards-editor.test.mjs` (15 JS tests pass). `npm run build`, `view:cache` and `route:cache` pass.
- E2E: the whole suite (`node tests/e2e/run.mjs`, specs 01 to 09, route sweep included) passed before the trunk merge; the booking specs pick the day first. The run after the merge is recorded in section 5.
- Browser (local server on 8740, Demo seeders): public storefront and guest search at 1440 and 375, booking day strip at 375 (no horizontal overflow) and 1440, the flashcard editor's "Add another card", terms page, 404.

## 5. After merging the trunk (polish-guardian included)

PHP 1009 (1002 pass, 7 skipped), JS 15 pass, build and caches pass, full E2E suite 01 to 09 passed (route sweep 641 s). The learner frame keeps `GuardianShell::frame()`; the team switcher rule (D-2023) sits in the shared `partials/frame`. No new monospace or money formatting was added.
