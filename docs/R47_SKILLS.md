# R-47 design skills record

Global owner rule: all UI work goes through the installed design skills. Each agent appends a section: which skills it invoked, what it applied, which were unavailable.

## Learning agent (wave 1, 2026-11-10)
- Invoked `impeccable` before writing the learning views. Its context launcher ran and reported no PRODUCT.md or DESIGN.md (the ds agent owns those), so the work was treated as a scoped Operate-mode surface inside the existing signed-in shell, not a new visual world; `init` was not run because wave 1 only ships minimal functional views.
- Applied its craft floor: AA contrast on the dark shell (body #e4e4e7, secondary #a1a1aa), visible focus rings, labelled controls and fieldsets, honest empty states, no eyebrows, no icon cards, no gradient text, tabular numerals in tables, themed text selection, copy that names actions and recovery.
- Ran `impeccable detect --json resources/views/learning`: no findings.
- Browser check with Playwright CLI (the shared Browser pane was in use by another agent) at 1440 px and 390 px on the course builder, course page, lesson player, library and quiz; fixed: repeat publish disabled and refused server-side, buy/enrol panel first on mobile, larger answer choices, clearer practice wording.
- Not claimed: `ui-ux-pro-max`, `emil-design-eng` (dangling), `hallmark` (not a new public page).
