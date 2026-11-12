# R-47 fix round after jury round 2

Round-2 cards: `scratchpad/tutor-jury-r2/critic-A.md` (6.39) and `critic-B.md` (6.1). This round fixes real defects (contradictions, wrong data, missing controls, wrong times, unusable phone layouts), not taste.

## fix-public (branch `feat/r47-fix-public`, decisions D-2300 to D-2311, port 8760)

| # | Critic item | What changed | Files |
|---|---|---|---|
| 1 | A 3, B 3: phone header loses its links, no menu | Menu button below 56rem (aria-expanded, aria-controls, click/Enter/Space, Tab trap, Escape returns focus, outside click closes); no-script fallback is a scrolling row; same nav on the error pages | `layouts/partials/public-nav`, `public-menu-button`, `public-top`, `errors/_ecosystem-layout`, `resources/js/ds/public-menu.js`, `ds/shells.css` |
| 2 | A 4, B 5: sign-in has no InfoDot route, no way in for new people; "Email Password Reset Link" | "Continue with InfoDot" only when `INFODOT_LAUNCH_URL` and the service secret are set (InfoDot's real `/go/tutor` launch page); "New here?" line either way; forgot-password copy rewritten | `App\Support\InfoDotSignIn`, `config/ecosystem.php`, `.env.example`, `auth/login`, `auth/forgot-password` |
| 3 | A 13, B 2: "Not yet verified" above "Verified by Dot.Tutor" | "Qualification checked, level pending" from one rule in `TutorPresenter`, used by the badge, `TutorStanding` and the matching reason | `TutorPresenter`, `TutorStanding`, `MatchingService`, `student/partials/verification`, `community/partials/tutors-tab` |
| 4 | A 5: booking ignores "weekdays 14:00 to 18:00" | The window travels from the search to booking; young learners start on after-school times; "Show all times"; Morning/Afternoon/Evening groups, three per row on phones | `Student\Support\BookingWindow`, `BookController`, `student/book`, `student/polish.css` |
| 5 | A 1: young step shows the adult filter wall and "You pay" | Subject, grade and the child's languages first, the rest behind "More choices"; the child's grade in the hint; "What your parent or guardian pays" / "Your parent or guardian pays" for minors | `student/find/index`, `FindComposer`, `student/book`, `student/lesson` |
| 6 | A 2: 3 unread but 7 "Unread" markers; arrow on its own line | Marker on the newest unread row per subject, "Earlier update" on the rest; arrow glued with a no-break space | `Comms\Inbox`, `comms/inbox`, `student/polish.css` |
| 7 | A 7: receipt Paid column cut off; caption flush; no Print; no seller | `stack` table, caption padding, Print button through a module, print stylesheet, "Sold by" from the frozen operator legal name (nothing when unset; no company name in code) | `student/money/receipt`, `resources/js/ds/print.js`, `student/polish.css` |
| 8 | A 9: deadline still a promise after it passed; report listed twice | "Overdue ..." or "raised to more senior staff at ..." from the incident's own record; the urgent report is not repeated on the child page | `Ops\Guardian\GuardianSafety`, `guardian/child` |
| 9 | A 6, B 4: result cards 360 to 470 px, "Next open times" not links | Two-row card; next times are links into booking with the time chosen (find results and storefront) | `student/find/index`, `student/storefront`, `student/polish.css` |
| 10 | A 8, B 10: colour-only hover, hard cut between pages | Nav bar draws in, arrows on the landing's secondary actions, policy links thicken; 180 ms CSS cross-fade under no-preference, skipped-fade promises caught | `ds/shells.css`, `welcome`, `student/polish.css`, `layouts/partials/head` |

### Not done, and why

- Guardian/40 "See the report" per row: a guardian has no report page to link to (report text stays with the officers, D-2101); rows that have a conversation already link to it.
- The verification level itself is not set from a verified record: that is an operator's decision; the ops prompt to set it is fix-ops' screen (D-2303).
- Match cards are about 243 to 322 px at 1440 (from 331 to 408) and 353 px on a phone, not quite the 200 to 220 px asked: the remaining height is the card's own reasons, which the honesty rules keep visible.
- The "Continue with InfoDot" button is not seen in production until the owner sets `INFODOT_LAUNCH_URL=https://infodot.co.za/go/tutor` (and InfoDot lists Dot.Tutor as live).

### Checks

- PHP: 1,016 before (1,009 pass, 7 skipped) and 1,027 after (1,020 pass, 7 skipped), SQLite. New tests are in `tests/Feature/FixPublic/FixPublicTest.php` (9). Two tests were added to existing files: `MoneyPagesTest` (receipt) and `GuardianExperienceTest` (overdue wording and no duplicate). No existing test changed.
- JS: `tests/js/fix-public.test.mjs` (5, the menu's focus trap).
- Browser (local server on 8760, Demo seeders, 390 and 1440): phone menu open/close and focus return, no overflow; sign-in (unconfigured state); guest and learner results; young find step and after-school booking on a phone; console clean after the cross-fade guard.
