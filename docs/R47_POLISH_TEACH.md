# R-47 polish wave: tutor workspace and operations (polish-teach, D-2200 onward)

Source: critic B (`scratchpad/tutor-jury/critic-B.md`), tutor and ops fixes. Branch `feat/r47-polish-teach`. Decisions D-2200 to D-2213 in `docs/R47_DECISIONS.md`; skills in `docs/R47_SKILLS.md`; audit rows in `docs/R47_AUDIT.md`.

## Critic fixes owned here

| Critic fix | What changed | Where |
|---|---|---|
| 1. Phone tables hide money and actions (tutor/41, 43, 50; ops/60, 63, 64, 67) | One shared pattern: `x-td.table stack`. Under 40rem each row is a card: title and key figure (You keep, Total, Respond by, Amount) on line 1, other fields as labelled pairs, the action across the foot. Applied to every list table in the workspace and ops (offerings, discount codes, earnings incl. totals, payouts and statements, students, packages, courses, materials, attempts, course learners; ops orders, refunds, payouts, batch, safeguarding reports, incidents, minors, screening, verification, users, audit, bookings, decisions). The child-safety "Open" is on screen. | `resources/views/components/td/table.blade.php`, `resources/js/ds/table-stack.js`, `resources/css/ds/responsive.css`, the views |
| 11. Tabs that clip (tutor/40, 47, 48; ops/60, 63) and no active tab on ops/61 | Every `.td-tabs` row snaps, centres the current tab on load and focus, and fades the side with more tabs. Ops section tabs mark the longest matching route, so the incident page marks Incidents. | `resources/js/ds/tabs-overflow.js`, `resources/css/ds/responsive.css`, `resources/views/ops/partials/tabs.blade.php` |
| 2. Two shells for one tutor (tutor/53, 54) and two hours editors (54 vs 39) | `/tutor/profile` forwards to `/teach/profile`, `/tutor/availability` to `/teach/calendar`; legacy views deleted; app-layout rail, dashboard, studio, assistant and notifications link to the workspace. Calendar days with no lessons fold on phones (fix 22). | `app/Http/Controllers/TutorProfileController.php`, `TutorAvailabilityController.php`, `resources/views/layouts/app.blade.php`, `resources/views/teach/calendar.blade.php` |
| 3. One verification status everywhere (tutor/45, 53; ops/59) | `x-td.tutor-standing` from `Integration\Support\TutorStanding`: Listed / Qualification verified, in review or not verified / Cleared or not cleared to teach under-18s, identical in the workspace profile and storefront and on ops/59. Profile approvals, rejections, suspensions and reinstatements appear in ops/59's Decisions panel with the operator's reason. The storefront preview shows the learner-facing label. | `app/Domain/Integration/Support/TutorStanding.php`, `resources/views/components/td/tutor-standing.blade.php`, `app/Domain/Ops/Http/Controllers/VerificationController.php`, `resources/views/ops/verification/tutor.blade.php`, `resources/views/teach/{profile,storefront,promo}.blade.php` |
| 4. Payment contradiction and duplicate rate (tutor/53) | The legacy page with "Payment is arranged outside the platform" is gone; the onboarding has no hourly-rate field and points to offerings. Existing rates are kept; a new profile has none; the old hourly booking form refuses a tutor without one. | `OnboardingController`, `resources/views/teach/profile.blade.php`, `app/Services/Scheduling/BookingService.php` |
| 9. Subject picker (tutor/53) | Searchable ds combobox per subject line, grouped by subject area, aliases as search words (maths, wiskunde, isiZulu HL); names never run into a phase. Not grouped by phase: the subject's legacy `level` is wrong for cross-phase subjects (D-2205). The combobox gained optional groups. | `AcademicOptions::subjectChoices()`, `resources/views/components/td/combobox.blade.php`, `resources/js/ds/combobox.js` |
| 10. Levels multi-select and misaligned cancellation inputs (tutor/44) | Grouped checkboxes per curriculum in disclosures (open where the tutor teaches); cancellation hours and refund % on one baseline with one shared note. | `resources/views/teach/offering-form.blade.php` |
| 12. Earnings row names the tutor (tutor/50) | Rows and the CSV name the learner and what was sold; the CSV has a Learner column. | `app/Domain/Teach/Services/EarningLabels.php`, `MoneyController`, `resources/views/teach/earnings.blade.php` |
| 21. Licence default (tutor/48) | All rights reserved by default; open licences explained in the options. | `resources/views/teach/materials.blade.php` |
| 13. Developer language (ops/60, 64, 65, 67; tutor/40) | `Ops\Support\PlainWords` for audit actions, people, record kinds and details; artisan commands out of the UI; Rows reads Entries; Exported once; bookings and pricing ledes in plain words. | `resources/views/ops/partials/audit-table.blade.php`, `ops/audit`, `ops/safeguarding/{queue,report}`, `ops/money/payouts`, `ops/overrides/academic/operator/{home,resource}`, `ops/users/team`, `teach/{bookings,offerings,offering-form,course}` |
| 14. Command-centre slips (ops/57) | No-show hint, plural answers, Country and Curriculum headings. The "Waiting for you" chevrons and the phone filler cells were not changed (see below). | `resources/views/ops/overview.blade.php` |
| 15. Charts (tutor/52, ops/57-m) | `App\Support\ChartScale::nice()`: integer 1/2/5 ticks, top above the maximum; phone chart text at about 11 to 12 px, every fourth x label. | `app/Support/ChartScale.php`, `resources/views/teach/partials/bar-chart.blade.php`, `resources/views/ops/partials/columns.blade.php` |
| 16. Operators and roles (ops/66) | One line per operator, roles in words, role checkboxes with summaries behind "Change roles", removal with a confirm naming the person, permission matrix behind a disclosure. | `resources/views/ops/users/team.blade.php`, `resources/css/ops.css` |
| 20 (part). Storefront chips repeat "Teaches in" (tutor/45) | The chip prefix is dropped under the "Teaches in" heading. | `resources/views/teach/storefront.blade.php` |
| 23. Verification form copy (ops/59) | "Reason (required to suspend)" or "(required to reject)" by state; "Verify teaching languages" is a button. | `resources/views/ops/verification/tutor.blade.php` |

## Not done, and why

- **A single Save for every operator's roles** (fix 16): needs a bulk method in `RoleAdministration`; per-person saves keep one audited before/after per change.
- **"Waiting for you" chevrons and grey phone filler cells** (fix 14, also tutor/51-m): the stat strip and work list are ds components shared with the learner side; left for the ds owner rather than restyled here.
- **Sidebar entries for Operators and Support** (fix 16): the operations rail already has "Users and roles" (`ops.users*`) and "Support" (`ops.support*`); not changed.
- **Public read-only storefront** (fix 20, second half): public pages belong to polish-learner.
- **Money numerals** (fix 8), classroom (7), public pages (5, 6, 17 to 19, 24): other polish owners.

## Cross-owner notes

- **ds**: new shared pieces: `x-td.table stack` (+ `table-stack.js`), the tab overflow behaviour on every `.td-tabs`, combobox groups, `x-td.tutor-standing`, `resources/css/ds/responsive.css` (one import line in `app.css`). The learner screens can use `stack` on their own tables.
- **polish-guardian (money font)**: stacked cells inherit whatever numeral style `x-td.money` ships; nothing here sets a money font.
- **marketplace**: the profile-wide hourly rate is optional for new tutors (D-2204); `TutorPresenter` already returns `hourly_rate_display: null` when it is unset, and matching falls back to offerings.
- **ops**: profile-standing audit entries now carry `reason` (D-2210).

## Checks

- `php artisan test --compact`: 987 tests, 980 pass, 7 skipped (trunk 980; +7 in `tests/Feature/Teach/PolishTeachTest.php`; updated: `TutorOnboardingTest`, `TutorAvailabilityTest`, `ResponsiveShellTest`, `TutorWorkspaceTest`, `CourseStudioAndMaterialsTest`, `CommandCentreScreensTest`, each where the behaviour changed on purpose). `RoutePermissions::MAP` untouched; `OperatorRouteMatrixTest` green.
- `node --test tests/js/*.test.mjs`: 17 pass (3 new in `tests/js/polish-teach.test.mjs`).
- `npm run build`, `view:cache`, `route:cache`: pass.
- E2E: see "E2E" below.
- Browser (390 and 1440, light and dark): see `docs/R47_SKILLS.md`.
