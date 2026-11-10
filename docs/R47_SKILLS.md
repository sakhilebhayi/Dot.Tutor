# R-47 design skills record

Global owner rule: all UI work goes through the installed design skills. Each agent appends a section: which skills it invoked, what it applied, which were unavailable.

## Marketplace agent (2026-11-10)

- Invoked `impeccable` before writing the four minimal journey views (`resources/views/marketplace/*`). Ran `impeccable context` (no PRODUCT.md or DESIGN.md yet; the ds agent owns the design system, so this was treated as a scoped build inside the existing app layout, not a new visual world) and read its craft floor. Applied: real labels on every field, the layout's existing focus ring, 44 px touch targets from the layout, honest copy (no claims beyond the data, "Not yet verified" by default, payment state stated), no eyebrows, no decorative cards, measure capped near 70ch. Checked in the browser at 375 px and 1440 px (no horizontal overflow); `impeccable detect` on the views returned no findings.
- Not invoked: hallmark, design-taste-frontend and the others, because these are wave-1 functional views that wave 2 replaces on the ds design system. `ui-ux-pro-max` and `emil-design-eng` are dangling symlinks and were not used.

