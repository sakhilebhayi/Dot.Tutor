# R-47 design skills record

Global owner rule: all UI work goes through the installed design skills. Each agent appends a section: which skills it invoked, what it applied, which were unavailable.

## safety agent (wave 1, 2026-11-10)

- Invoked `impeccable` before writing the safety views and ran `impeccable context` (no PRODUCT.md or DESIGN.md yet: treated as scoped work inside the incumbent dark signed-in shell, as the ds agent owns the design system). Read and applied `reference/craft-floor.md`: semantic headings, every form control labelled, `role=alert` and `role=status` for errors and confirmations, visible `:focus-visible` rings, tabular numerals in tables, themed text selection, body measure capped at 68ch, no eyebrow labels, no coloured side borders, no card grids, controls named by their action, errors that name the recovery.
- Ran `impeccable detect --json` over `app/Domain/Safety/resources/views`: no findings.
- Browser check at 390 px and 1440 px (guardian dashboard and a conversation with a redacted message): no horizontal overflow; approving a request from the page worked.
- Wave 1 views are functional only (one shared frame component, `safety::frame`); the full guardian, learner and operator screens are wave-2 work on the ds design system.
- Not used: `ui-ux-pro-max` and `emil-design-eng` (dangling symlinks on this machine).

