# R-47 student UI (student UI agent, decisions D-1100 to D-1121)

The learner-facing screens of Dot.Tutor, on the design system (`DESIGN.md`, `docs/R47_DS.md`) in the learner shell. Brief sections 1, 6, 8, 10, 13, 23, 25 and the final product standard. Branch `feat/r47-student`.

## 1. How it is built

- **Owned code:** `app/Domain/Student/**` (provider `TutorStudentServiceProvider`, controllers, composers, `LearnerContext`, `LearnerAgenda`, `NeedReader`), `routes/tutor/student.php`, `resources/views/student/**`, `resources/views/components/student/shell.blade.php`, `resources/css/student/student.css`, `resources/js/student/*.js`, migration `2026_11_10_110000_create_student_preferences_table`, `database/seeders/DemoStudentSeeder.php`, tests `tests/Feature/Student/StudentScreensTest.php` and `tests/js/student.test.mjs`.
- **New screens** live under `/learn` and call the owning domains' services (marketplace `BookingFlow`, `PaymentService`, `ReviewService`, `Idempotency`, `SlotFinder`; learning `ProgressFeed`, `ProgressService`, `SpacedRepetition` and its `FlashcardController` actions; academic `LearnerProfiles`, `NeedParser`, `LanguageProfile`, `LocaleCatalogue`; safety `AccountStanding`, `Guardians`; money `Order`, `Receipt`, `PaystackConfig`; community `Post`, `RoomMember`).
- **Wave-1 learner views are replaced, their controllers are not** (D-1103): each domain view file now `@include`s a student view, and the domain controller's data passes through unchanged. View composers add presentation data only (choice lists, the reader's context, explained match lines, open times). Domain behaviour is unchanged except the two small fixes in section 5.
- **One shell for every learner screen:** `<x-student.shell title="...">` wraps `<x-layouts::learner>` and sets the reading step from `LearnerContext` (D-1101). It also shows the `status` flash that domain controllers send (the ds toasts read other keys).

## 2. The young step (D-1101)

Decided in one place, `App\Domain\Student\Services\LearnerContext`:

1. A person who chose a view in their account (`student_preferences.step`: `young` or `standard`) gets it.
2. Otherwise a learner whose level is in the Foundation or Intermediate phase (CAPS and IEB Grades 1 to 6) or a Cambridge Primary stage gets **young**.
3. Otherwise a learner with no level recorded whose known age band is child (under 13) gets **young**.
4. Everyone else gets **standard**. Unknown age never switches it on: the step is a reading aid; safety is enforced server-side regardless.

Young means the ds step (19px body, 56px controls, fewer nav items: Courses, Messages and Payments leave the rail; "Find a tutor" becomes "Get help", "Study assistant" becomes "Ask a question") plus page-level reductions: fewer insight sentences, no ranking table on matches, no review distribution, shorter attempt lists, simpler headings ("Hi Lerato", "What do you need help with?"). Checked as a Grade 3 learner and a first-year university learner in the browser and in `test_a_grade_3_learner_gets_the_young_step_and_a_university_learner_does_not`.

## 3. Screens, routes and what each calls

| Screen | Route name (URL) | Nav item lit | Calls |
|---|---|---|---|
| Home | `learn.home` (`/learn`) | Home | `LearnerAgenda` (bookings, seats, enrolments + `ProgressService::summary`, assignments without a submitted attempt, `ProgressFeed::insights`, unanswered questions in the learner's rooms), `BookingPresenter::nextStep`, `AiGateway::configured` |
| Tell us what you need | `learn.need` (POST `/learn/need`) | | `NeedReader` (academic `NeedParser` + budget, format, kind of help, after school/weekends; profile defaults said as such) then redirects to `marketplace.find` |
| Onboarding | `learn.onboarding` (`/learn/welcome`), `learn.onboarding.store` | Learning profile | academic `LearnerProfileController::edit` data, `SaveLearnerProfileRequest`, `LearnerProfiles::save`, `LanguageProfile::sync` (interface role); minors without consent go to the guardian step (`?step=guardian`, safety's invite form) |
| Learning profile | `academic.profile.edit` (`/learn/profile`) | Learning profile | academic controller, view replaced |
| Find a tutor | `marketplace.find` (`/find-a-tutor`), `match.search` (`/learn/find`) | Find a tutor | marketplace `MatchingService` via its controller; `FindComposer` turns the matcher's terms into fits/gaps lines, adds teaching-language chips (`LanguageProfile::teachingLanguagesFor`), formats and a "How this was ranked" table of the real weights and points. `match.search` fills the curriculum from the level and drops empty fields |
| Tutor storefront | `marketplace.tutors.show` (`/t/{id}`) | Find a tutor | `TutorPresenter::storefront`; `StorefrontComposer` adds published courses and library items (learning), published review texts, next open times (`SlotFinder`); report form (safety `tutor_profile`) |
| Book | `learn.book`, `learn.book.store` (`/learn/book/{offering}`) | Find a tutor | `SlotFinder::forTutor` for real times; `SafeguardingPort::allows('book')` to explain a refusal up front; `BookingFlow::requestBooking`, `bookSeries`, `buyPackage`, `requestSeat` under `Idempotency::run` with a per-page key; package payment through `PaymentService::start` |
| Lessons | `marketplace.bookings` (`/my/bookings`) | Lessons | marketplace controller; `LessonsComposer` adds seats and packages; tutor requests keep accept/decline |
| Lesson page | `learn.lessons.show` (`/learn/lessons/{id}`), `.pay`, `.cancel`, `.review` | Lessons | `TutorSessionPolicy::view`; `BookingPresenter::session` events as the timeline; `PaymentService::start` (redirect to Paystack's own checkout); `BookingFlow::cancel`/`cancelSeat` with the refund preview mirrored from the marketplace rule; `ReviewService::review`; comms calendar file; the classroom link appears when a `classroom.show|join|room` route exists |
| Course catalogue | `learning.courses.index` | Courses | learning controller (+ level and curriculum filters, section 5) |
| Course page | `learning.courses.show` | Courses | learning controller: price, objectives, workload, refund policy, outline with previews before buying; enrol, buy (`CheckoutGateway`), upgrade, course review |
| Course player | `learning.learn` | Courses | learning controller; signed media URLs; resume script moved to `resources/js/student/player.js` |
| My courses | `learn.courses` (`/learn/courses`) | Courses | enrolments + `ProgressService::summary`, certificates |
| Study library | `learning.library.index`, `learning.library.show` | Study library | learning controller; original-language label, licence, quality tier, buy (`library.checkout`), recommend, report and counter-notice, "Original and translation" (ai `ai.translations.show`) |
| Practice | `practice.index`, `practice.cards.*` (`/learn/practice`) | Practice | assignments, course quizzes, submitted attempts; flashcards created and graded through learning's `FlashcardController::store` and `::review` (same validation and SM-2 rules) |
| Quiz | `learning.assessments.take`, `learning.attempts.show` | Practice | learning controller, view replaced |
| Progress | `learn.progress` (`/learn/progress`) | Progress | `ProgressFeed::insights`, `mastery` (words, not scores: Going well, Getting there, Needs more practice, Too few answers to say), `recentEvents`; no leaderboards |
| Purchases | `money.orders` (`/learn/purchases`) | Payments | money `Order` and `Receipt` for the buyer |
| Checkout, receipt | `money.checkout.show`, `money.receipts.show` | Payments | money controller, views replaced (test mode said when Paystack is in test mode; "not enabled" said when it is off) |
| Account and languages | `learn.account`, `learn.account.step` (`/learn/account`) | Account | `LanguageProfile` roles, `LocaleCatalogue::options` (coverage and provenance shown), the step preference, `AccountStanding::summary`, `Guardians::guardiansOf`, pending invitations, links to purchases, notifications, InfoDot account |
| Language switcher | `academic.locale.index` (`/language`) | Account | academic controller; signed-in learners get the student page, guests the academic page |
| Study assistant | `ai.index`, `ai.need.form`, `ai.translations.show` | Study assistant | ai controllers; views moved onto `x-student.shell` (child band still forces young) |
| Notifications | `comms.inbox` | Notifications | comms; the bell `<x-comms::bell />` sits in the shared top bar |

Navigation (`config/tutor_nav.php`, learner): Home, Find a tutor (young: Get help), My rooms, Lessons, Courses (standard only), Study library, Practice, Progress, Study assistant (young: Ask a question), Messages (safety conversations, standard only); You: Learning profile, Notifications, Guardian (when the guardian UI lands), Payments (standard only), Account. Phone bar: Home, Find, My rooms, Lessons.

## 4. Honesty notes

- No figure is invented: an empty list says what fills it. Money is integer minor units through `x-td.money` or money's `Money::format`.
- The booking price line says the price is the whole price and that Dot.Tutor's commission comes out of the tutor's share (money design D-503).
- The cancel preview mirrors `BookingFlow::refundDue`; the booking event records the real figure, and refunds are processed by finance (stated).
- Verification: the marketplace's own label ("Not yet verified") when no level was assigned; the ds badge only for an assigned level.
- Interface languages: only English is complete; the account and onboarding pages show each locale's measured coverage and whether it was AI-drafted. The new student strings are English only (D-1119), so they lower other locales' coverage honestly.
- AI: links to the assistant say when it is not switched on. Nothing on these screens calls a model.
- Times are shown in `tutor.timezone` (SAST) and every page with times says so (D-1104).

## 5. Changes in other domains (with tests)

- **Marketplace** `MarketplacePageController::find` used `only(array_keys(rules()))`, which includes `languages.*` and `weekdays.*` and nested list query values, so `?languages[]=zu` was refused. It now reads top-level keys only and also accepts comma lists for weekdays and a `1`/`0` bilingual flag (D-1117; `test_the_find_page_accepts_lists_of_languages_and_days`).
- **Learning** `CatalogueController::index` gains `level_id` and `curriculum_id` filters (D-1118).
- **Shared files:** one `require` line in `routes/web.php`, one provider line in `bootstrap/providers.php`, one `@import` in `resources/css/app.css`, one `import` in `resources/js/app.js`, the learner section of `config/tutor_nav.php` (route candidates and match patterns for the screens that now exist, plus Study assistant and Notifications), the bell in `layouts/partials/frame.blade.php` (one line, guarded by `Route::has('comms.inbox')`). Tests updated because behaviour legitimately changed: `DesignSystemKitTest` (nav now lists the built screens), `LearningScreensTest` (price prints as `R249.00`, the ds money format).

## 6. Checks run

- `php artisan test --compact` after merging the trunk (with community and classroom): 854 tests, 847 pass, 7 skipped, 0 fail (779 / 772 / 7 at this branch's first trunk merge). `tests/Feature/Student/StudentScreensTest.php` (14 tests) covers guests, home, young vs university step and overrides, onboarding (teen to guardian step, adult home, required month of birth), plain words to explained matches, unknown subject, filter normalising, list query values, storefront to booking to lesson page (double submit books once, other learner 403, Paystack redirect with faked HTTP, confirmed, cancel with full refund due), review once, a limited minor refused server-side, learning screens, flashcards create/review/grade/refuse, nav lit and bell present, the classroom link on a confirmed lesson. `node --test tests/js/student.test.mjs` (3).
- Browser (local server 8710, `DemoStudentSeeder` + `DemoCommunitySeeder`): every learner page returned 200 (an unassigned quiz 403, another learner's lesson 403); no horizontal overflow on 20 pages at 390 and 1440; screens viewed at 390 and 1440 in light and dark as a Grade 3 learner, a Grade 12 learner and a university learner; keyboard only: skip link, rail, top bar, form fields and submit in order with a 2.5px focus outline; a flashcard set created, opened and graded by keyboard alone. Screenshots: `docs/R47_screens/student/`.
- Fixes from the browser check: price row under the name on phones, menu button icon-only below 480px so the title is readable with the bell, open times as a plain list.
- `impeccable detect` on `resources/views/student` and `resources/css/student`: one advisory (1px radius) fixed, then none. Taste `lint.mjs` on the stylesheet: 0 FAIL, 6 WARN (1px panel edges, which DESIGN.md specifies for app screens).

## 7. Not built, or left to others

- After sign-in Fortify still lands on `/dashboard` (legacy). Pointing learners at `/learn` is a shared-config change for the integration or ops agent.
- No learner timezone is stored; times are platform time (D-1104).
- Group lessons and packages are bookable from the book page; recurring series are requested from it; reschedule does not exist in the marketplace.
- Course reviews list (learning JSON only) is not shown on the course page beyond the average.
- The classroom join link uses the classroom domain's `classroom.show` route for confirmed and in-progress lessons (merged from trunk); without that route the lesson page says the tutor shares how to join.
- Interface strings added here are English only; translators add `lang/<code>` files later (I18N doc).
- Guardian screens are the ops and guardian UI agent's (D-1300); the learner side links to safety's account page.
