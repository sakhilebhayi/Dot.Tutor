# R-47 polish wave: guardian experience, money figures, classroom states (polish-guardian, D-2100 to D-2108)

Branch `feat/r47-polish-guardian`. Inputs: critic A (`tutor-jury/critic-A.md`, guardian 4 to 5) and critic B (`critic-B.md`, classroom and money typography). Skills: `docs/R47_SKILLS.md`. Decisions: `docs/R47_DECISIONS.md` D-2100 to D-2108. Audit rows: `docs/R47_AUDIT.md`, "Polish wave: guardian, money figures, classroom".

## 1. Each critic fix this agent owns

| Critic fix | What changed | Files |
|---|---|---|
| A1, guardian/36: `/safety/guardian` showed the home again | The Safety page: one row per report, safeguarding case or account notice about the guardian's own children, with what, where it was made, date, status, what happens next and the guardian's action (read the conversation; write to support). Empty state "No reports about your children". Police 10111 and Childline 116. JSON of the route unchanged. | `app/Domain/Ops/Guardian/GuardianSafety.php`, `resources/views/guardian/safety.blade.php`, `resources/views/guardian/partials/safety-row.blade.php`, `resources/views/guardian/overrides/safety/guardian-dashboard.blade.php` |
| A2, guardian/35 and 34: urgent report in a side card, green "Active" | Full-width danger band above "Waiting for you" on the home and child pages, with status, next step, "See the report" (links to the row) and the emergency numbers. Child badge: "1 urgent report" (links to the row), else suspended, needs consent, "1 open report", "N waiting for you", then "Active". Child page reports panel moved up and shows the rows. | `resources/views/guardian/partials/urgent.blade.php`, `home.blade.php`, `child.blade.php` |
| A6, guardian shell: learner nav, no phone tab | Guardian navigation (Children, Approvals, Safety, Messages, Payments; Notifications, Your learning when they learn too, Account) on desktop and the phone bar (first four). Messages for a guardian render in the guardian frame. No dead links: every item is a built route (tested). | `app/Domain/Ops/Guardian/GuardianShell.php`, `config/tutor_nav.php` (`guardian` block), `resources/views/layouts/learner.blade.php` (frame call), `resources/views/guardian/messages.blade.php`, `conversation.blade.php`, overrides `safety/conversations`, `safety/conversation` |
| A7, guardian/37: declined request left no trace | "Recent decisions" on the home (five, with a link to all) and on the new Approvals page: what, child, amount, date, "You declined" / "Another guardian approved", the reason. Expired requests show "Nobody decided in time". "Message Kabelo" not built: safety refuses adult-to-minor direct conversations. | `GuardianView::decisions`, `resources/views/guardian/partials/decisions.blade.php`, `approvals.blade.php`, route `guardian.approvals` |
| A16 (guardian part): "Under 13, your parent."; "Requested" vs "Waiting for the tutor" | "Under 13 · you are their parent"; lesson states use the child's words (Waiting for the tutor, Waiting for you, Waiting for payment, Confirmed, Happening now). | `child.blade.php` |
| A12 and B8: slashed zero, "R 150.00" | Digits from `TD Figures` (Archivo at normal width, digits only, matched to Atkinson's cap height and metrics) first in the text stack; tiles at normal width (one step smaller on a phone). One money path: `Money::format()` "R1 250.00"; `x-td.money`, `App\Support\Money`, `MinorUnits::format`, `learning.partials.money` delegate to it. | `resources/css/ds/fonts.css`, `tokens.css`, `components.css`, `app/Domain/Money/Money.php`, `app/Support/Money.php`, `app/Domain/Marketplace/Support/MinorUnits.php`, `resources/views/components/td/money.blade.php`, `resources/views/learning/partials/money.blade.php`, `DESIGN.md` (typography note) |
| B7, classroom/56: "Not joined" next to "The tutor ended this lesson" | Chip from the admission: Ended, Closed, Cancelled, Not open yet, Not joined. The tutor of an ended lesson gets a notice and "Reopen the lesson". The connection help is left out once the lesson is over. | `app/Domain/Classroom/resources/views/room.blade.php`, `resources/css/classroom.css` |
| B7: "relay server" wording to learners | Learners and guardians: "Some school and office networks block video calls" and the tutor's meeting link (pre-join check, help block, in-call banner). Tutors keep the technical reason and the link form. | `room.blade.php`, `resources/js/classroom/room.js` (`cannotConnect`) |
| B7, classroom/55-m: Join ~1,200 px down | Join directly under the preview on a phone (full width), under the checks at desk width; the WebRTC client, ids and spec 08 unchanged. | `room.blade.php`, `classroom.css` |

Also fixed on the way: the child page's Purchases panel rendered an empty list when a purchase request existed but no order did.

## 2. Why the figures did not use "the body font's tabular figures"

Both critics took the slashed zero for a monospace. It is the body face itself: Atkinson Hyperlegible Next draws 0 with a slash so it never looks like O, and the font has no plain-zero alternate (its GSUB features are ccmp, frac, locl, pnum, tnum; its zero glyph is three contours). Turning a feature off was impossible, and editing the glyph would mean shipping a modified OFL font. The system already loads Archivo for headings, so a digits-only `@font-face` on the same file costs no extra download. The figures are a shade firmer than 400 text (the file's lightest instance is 500); in prices and times that reads as intended. Checked at 1440 and 390 on guardian, learner, tutor and operations screens: no overflow, tables keep one right edge.

## 3. Guardian data and scope

- Everything comes from rows the guardian may already see: safety's guardian dashboard scope, money orders of their linked children, approvals for their linked children. A stranger sees nothing (tested on every new page).
- Never shown to a guardian: report text, report snapshot, officer notes, case outcome, the name of anyone else who filed a report. A child's own report says the child filed it; other reports say only where they were made.
- "What happens next" uses the real mechanics: an unacknowledged case must be taken on by its `respond_by` and is raised automatically (`safety:escalate-incidents`); an open report shows its `respond_by`.

## 4. Tests

`tests/Feature/PolishGuardian/` (13 tests): `GuardianExperienceTest` (navigation desktop and phone, no learner items, every item answers 200, guardian who also learns, minor on /guardian keeps learner nav; Safety rows, scope, minimisation, incident states, JSON unchanged; reporter not named; urgent band first and badge; recent decisions incl. another guardian and a stranger; payments scope and money format; messages in the guardian frame vs safety's page for the child), `ClassroomStatesTest` (learner wording and Join order; ended chip for learner and tutor, help block gone; tutor keeps the technical text), `MoneyTypographyTest` (every money path, the figures face). Updated for intended behaviour: `Ops\GuardianViewTest` (`/safety/guardian` is the Safety page now) and the money-format assertions listed in D-2104.

## 5. Contract notes for other owners

- **polish-learner / ds**: `layouts/learner.blade.php` now takes its scope, nav and bottom bar from `GuardianShell::frame($step)` (falls back to the learner nav). If you change that file, keep the call. The team switcher (critic A13) is still in the shared frame for guardians too; it is yours to remove for all three roles.
- **polish-teach / everyone**: money text must come from `Money::format()` or `x-td.money`; do not hand-build "R " strings. Tile values (`.td-stats dd`) are at normal width now.
- **classroom**: learner-facing copy must not name relay or TURN servers; the tutor's copy may.
