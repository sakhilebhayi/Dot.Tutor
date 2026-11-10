# R-47 design skills record

Global owner rule: all UI work goes through the installed design skills. Each agent appends a section: which skills it invoked, what it applied, which were unavailable.

## safety agent (wave 1, 2026-11-10)

- Invoked `impeccable` before writing the safety views and ran `impeccable context` (no PRODUCT.md or DESIGN.md yet: treated as scoped work inside the incumbent dark signed-in shell, as the ds agent owns the design system). Read and applied `reference/craft-floor.md`: semantic headings, every form control labelled, `role=alert` and `role=status` for errors and confirmations, visible `:focus-visible` rings, tabular numerals in tables, themed text selection, body measure capped at 68ch, no eyebrow labels, no coloured side borders, no card grids, controls named by their action, errors that name the recovery.
- Ran `impeccable detect --json` over `app/Domain/Safety/resources/views`: no findings.
- Browser check at 390 px and 1440 px (guardian dashboard and a conversation with a redacted message): no horizontal overflow; approving a request from the page worked.
- Wave 1 views are functional only (one shared frame component, `safety::frame`); the full guardian, learner and operator screens are wave-2 work on the ds design system.
- Not used: `ui-ux-pro-max` and `emil-design-eng` (dangling symlinks on this machine).

## money (wave 1)
- Invoked `impeccable` before writing the four minimal pages (checkout, receipt, tutor earnings, finance operations). `impeccable context` reported no PRODUCT.md or DESIGN.md (the ds agent owns them), so the pages were treated as a scoped Operate surface on the incumbent signed-in layout; read the craft floor and applied it: real states (payment off, test mode, held for a guardian, closed, paid), tabular numerals for money, focus rings, no eyebrows or card grids, responsive line tables (secondary columns hidden under 640 px, no horizontal page scroll), print styles for receipts.
- Checked in the browser at 375 px and 1440 px (checkout and receipt); `impeccable detect` on `app/Domain/Money/Views`: no findings.
- Not used: hallmark (no new visual world in wave 1), `ui-ux-pro-max` and `emil-design-eng` (dangling symlinks).
## Learning agent (wave 1, 2026-11-10)
- Invoked `impeccable` before writing the learning views. Its context launcher ran and reported no PRODUCT.md or DESIGN.md (the ds agent owns those), so the work was treated as a scoped Operate-mode surface inside the existing signed-in shell, not a new visual world; `init` was not run because wave 1 only ships minimal functional views.
- Applied its craft floor: AA contrast on the dark shell (body #e4e4e7, secondary #a1a1aa), visible focus rings, labelled controls and fieldsets, honest empty states, no eyebrows, no icon cards, no gradient text, tabular numerals in tables, themed text selection, copy that names actions and recovery.
- Ran `impeccable detect --json resources/views/learning`: no findings.
- Browser check with Playwright CLI (the shared Browser pane was in use by another agent) at 1440 px and 390 px on the course builder, course page, lesson player, library and quiz; fixed: repeat publish disabled and refused server-side, buy/enrol panel first on mobile, larger answer choices, clearer practice wording.
- Not claimed: `ui-ux-pro-max`, `emil-design-eng` (dangling), `hallmark` (not a new public page).
## academic agent (wave 1, functional views only)

- **impeccable**: invoked before writing the learner profile, locale switcher and operator views. `impeccable context` ran (no PRODUCT.md/DESIGN.md yet: the ds agent owns them). Applied from `craft-floor.md`, `clarify.md` and the i18n part of `harden.md`: persistent labels (placeholders only as examples), controls that name their action ("Save my learning profile", "Use this language"), errors that say what to fix, why birth month is asked, flexible widths for translated text (no fixed-width buttons or labels), `lang` attributes on language names, honest empty states, no eyebrow labels. `impeccable detect --json app/Domain/Academic/resources/views` returned no findings. Browser check at 390 px and 1440 px on the local server (profile form, locale switcher, operator levels screen).
- Not applied: no visual direction work (wave 2 restyles on the ds design system). `ui-ux-pro-max` and `emil-design-eng` were not used (dangling symlinks).
## Marketplace agent (2026-11-10)

- Invoked `impeccable` before writing the four minimal journey views (`resources/views/marketplace/*`). Ran `impeccable context` (no PRODUCT.md or DESIGN.md yet; the ds agent owns the design system, so this was treated as a scoped build inside the existing app layout, not a new visual world) and read its craft floor. Applied: real labels on every field, the layout's existing focus ring, 44 px touch targets from the layout, honest copy (no claims beyond the data, "Not yet verified" by default, payment state stated), no eyebrows, no decorative cards, measure capped near 70ch. Checked in the browser at 375 px and 1440 px (no horizontal overflow); `impeccable detect` on the views returned no findings.
- Not invoked: hallmark, design-taste-frontend and the others, because these are wave-1 functional views that wave 2 replaces on the ds design system. `ui-ux-pro-max` and `emil-design-eng` are dangling symlinks and were not used.

