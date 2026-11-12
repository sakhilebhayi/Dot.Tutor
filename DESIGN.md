---
version: alpha
name: Dot.Tutor Reading Room
description: The Dot.Tutor design system. A lending library of people, from Grade 1 to doctoral study. Source of truth for values is resources/css/ds/tokens.css (OKLCH); the hex values here are its sRGB rendering for tools.
colors:
  ground: "#F3F8F5"
  surface: "#FBFEFC"
  sunken: "#E8EFEC"
  line: "#D2DCD7"
  edge: "#707E77"
  ink: "#11211A"
  ink-2: "#414E48"
  ink-inverse: "#F3F8F5"
  primary: "#115B3E"
  primary-hover: "#004A30"
  on-primary: "#F7FBF9"
  primary-soft: "#D4F0E1"
  on-primary-soft: "#004129"
  success-soft: "#D9F3DD"
  on-success-soft: "#05441D"
  warning-soft: "#FEEDC9"
  on-warning-soft: "#683C00"
  danger: "#B32F28"
  on-danger: "#F7FBF9"
  danger-soft: "#FFE7E3"
  on-danger-soft: "#8D1B17"
  info-soft: "#E4F0FF"
  on-info-soft: "#164781"
  stage-foundation: "#EDB333"
  on-stage-foundation: "#2E1E0A"
  stage-intermediate: "#55A144"
  on-stage-intermediate: "#0C1A08"
  stage-senior: "#017B80"
  on-stage-senior: "#F6FBFC"
  stage-fet: "#2750A2"
  on-stage-fet: "#F8FAFE"
  stage-undergraduate: "#892A29"
  on-stage-undergraduate: "#FEF9F8"
  stage-postgraduate: "#642A53"
  on-stage-postgraduate: "#FDF9FB"
  stage-doctoral: "#103328"
  on-stage-doctoral: "#EFF7F4"
  stage-professional: "#495B67"
  on-stage-professional: "#F7FBFE"
  night-ground: "#0D1511"
  night-surface: "#141D19"
  night-ink: "#E8EFEB"
  night-ink-2: "#B4BDB9"
  night-primary: "#6DC799"
  on-night-primary: "#051810"
typography:
  display:
    fontFamily: Archivo
    fontSize: 6rem
    fontWeight: 780
    lineHeight: 1.02
    letterSpacing: -0.02em
    fontVariation: "'wdth' 75"
  section:
    fontFamily: Archivo
    fontSize: 3rem
    fontWeight: 760
    lineHeight: 1.04
    letterSpacing: -0.015em
    fontVariation: "'wdth' 75"
  page-title:
    fontFamily: Archivo
    fontSize: 1.75rem
    fontWeight: 750
    lineHeight: 1.15
    fontVariation: "'wdth' 75"
  title:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 1.375rem
    fontWeight: 700
    lineHeight: 1.15
  lead:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 1.125rem
    fontWeight: 400
    lineHeight: 1.5
  body:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 1rem
    fontWeight: 400
    lineHeight: 1.55
    fontFeature: "'tnum' 1"
  label:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.875rem
    fontWeight: 650
    lineHeight: 1.3
  meta:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.8125rem
    fontWeight: 400
    lineHeight: 1.4
  body-young:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 1.1875rem
    fontWeight: 400
    lineHeight: 1.7
  body-dense:
    fontFamily: Atkinson Hyperlegible Next
    fontSize: 0.9375rem
    fontWeight: 400
    lineHeight: 1.45
rounded:
  none: 0px
  sm: 4px
  young: 8px
  pill: 999px
spacing:
  "1": 4px
  "2": 8px
  "3": 12px
  "4": 16px
  "5": 24px
  "6": 32px
  "7": 48px
  "8": 64px
  "9": 96px
  "10": 128px
  gutter-phone: 16px
  gutter-tablet: 32px
  gutter-wide: 48px
  container: 1344px
  control: 44px
  control-young: 56px
  control-dense: 36px
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    height: "{spacing.control}"
    padding: 16px
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.on-primary}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    height: "{spacing.control}"
    padding: 16px
  button-danger:
    backgroundColor: "{colors.danger}"
    textColor: "{colors.on-danger}"
    rounded: "{rounded.sm}"
    height: "{spacing.control}"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    height: "{spacing.control}"
    padding: 12px
  language-chip:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    height: 32px
  language-chip-match:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.on-primary-soft}"
    rounded: "{rounded.pill}"
  pill-stage-fet:
    backgroundColor: "{colors.stage-fet}"
    textColor: "{colors.on-stage-fet}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    height: 28px
  pill-stage-foundation:
    backgroundColor: "{colors.stage-foundation}"
    textColor: "{colors.on-stage-foundation}"
    rounded: "{rounded.sm}"
  badge-verified:
    backgroundColor: "{colors.success-soft}"
    textColor: "{colors.on-success-soft}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
  badge-review:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.on-info-soft}"
    rounded: "{rounded.sm}"
  badge-peer:
    backgroundColor: "{colors.sunken}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
  badge-lapsed:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.on-danger-soft}"
    rounded: "{rounded.sm}"
  notice-warning:
    backgroundColor: "{colors.warning-soft}"
    textColor: "{colors.on-warning-soft}"
    rounded: "{rounded.sm}"
    padding: 16px
  panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.none}"
    padding: 24px
  nav-link-current:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.on-primary-soft}"
    rounded: "{rounded.sm}"
    height: 44px
  toast:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.ink-inverse}"
    rounded: "{rounded.sm}"
  page:
    backgroundColor: "{colors.ground}"
    textColor: "{colors.ink}"
  page-secondary-text:
    backgroundColor: "{colors.ground}"
    textColor: "{colors.ink-2}"
  divider:
    backgroundColor: "{colors.line}"
    textColor: "{colors.ink}"
  field-edge:
    backgroundColor: "{colors.edge}"
    height: 1px
  pill-stage-intermediate:
    backgroundColor: "{colors.stage-intermediate}"
    textColor: "{colors.on-stage-intermediate}"
    rounded: "{rounded.sm}"
  pill-stage-senior:
    backgroundColor: "{colors.stage-senior}"
    textColor: "{colors.on-stage-senior}"
    rounded: "{rounded.sm}"
  pill-stage-undergraduate:
    backgroundColor: "{colors.stage-undergraduate}"
    textColor: "{colors.on-stage-undergraduate}"
    rounded: "{rounded.sm}"
  pill-stage-postgraduate:
    backgroundColor: "{colors.stage-postgraduate}"
    textColor: "{colors.on-stage-postgraduate}"
    rounded: "{rounded.sm}"
  pill-stage-professional:
    backgroundColor: "{colors.stage-professional}"
    textColor: "{colors.on-stage-professional}"
    rounded: "{rounded.sm}"
  footer:
    backgroundColor: "{colors.stage-doctoral}"
    textColor: "{colors.on-stage-doctoral}"
  night-page:
    backgroundColor: "{colors.night-ground}"
    textColor: "{colors.night-ink}"
  night-panel:
    backgroundColor: "{colors.night-surface}"
    textColor: "{colors.night-ink-2}"
  night-button-primary:
    backgroundColor: "{colors.night-primary}"
    textColor: "{colors.on-night-primary}"
    rounded: "{rounded.sm}"
---

## Overview

Dot.Tutor is a lending library of people. Every academic level has its own shelf, every subject its own room, and every tutor a catalogue record that says exactly what they teach, at which levels, in which languages, and what has been verified. The look borrows the library's working systems rather than its nostalgia: classification, binding cloth and spine labels, set in a calm reading-room light. It must serve a Grade 1 learner on a parent's phone and stay credible to a university lecturer, so one system carries three density steps (young, standard, dense) instead of two brands.

It refuses the tutoring-marketplace default (a search-bar hero over a grid of smiling tutor cards with stars), generic SaaS cards, gradients, glass, decorative motion, and the three generated looks the Dot ecosystem bans (cream with a serif and terracotta; near-black with one neon accent; broadsheet hairlines with tracked mono labels).

## Colors

Restrained on screens people work in, committed on the landing. The ground is shelf-label white tinted toward reading-room green (never cream); ink is a deep green-black; one reading-room green marks the primary action and the current place, nothing else.

- **Ground, surface, sunken:** three tone steps separate regions without shadows. Panels share 1px edges over the line colour instead of floating.
- **Ink and ink-2:** running text is always ink. Ink-2 is for secondary lines and passes 4.5:1 on ground, surface and sunken.
- **Edge:** field and control outlines, 3:1 or better on every surface.
- **Primary (reading-room green):** primary buttons, the current navigation item (soft tint), selection, links.
- **Signals:** success, warning, danger and info each come as a soft ground with an ink that reads 4.5:1 on it. A state is never shown by colour alone: every badge and notice carries a glyph and words.
- **Stage binding colours:** eight cloth colours, one per academic stage (foundation marigold, intermediate leaf, senior teal, FET cobalt, undergraduate oxblood, postgraduate plum, doctoral deep green, professional slate), each paired with the ink that reads on it. They are data: they say which level something belongs to. They are never used as decoration.
- **Night:** the same room after dark. Follows the device unless the person picks a theme (stored on that device only). The night values are listed with a `night-` prefix; the full set lives in `resources/css/ds/tokens.css`.

## Typography

Two self-hosted families under the SIL Open Font License, no third-party hosts.

- **Archivo** (Omnibus-Type), instanced to width 62-100 and weight 500-800, set condensed at width 75 for headings: the spine-label voice. Sentence case, never tracked capitals.
- **Atkinson Hyperlegible Next** (Braille Institute) for everything people read and operate. Its letterforms keep I, l and 1 and O and 0 apart, which matters for early readers, maths and codes. Figures are tabular everywhere so prices, times and marks line up. Its zero is slashed by design (no plain alternate), which made prices read as "R25Ø.ØØ" (both jury critics, polish wave), so the digits 0 to 9 come from Archivo at its normal width through a digits-only face, `TD Figures`, first in the text stack and matched to Atkinson's cap height and line metrics (D-2103). Money is written one way: the symbol against the figure, "R1 250.00" (D-2104).
- **Scale:** meta 13, label 14, body 16, lead 18, title 22, page title 28, section 32 to 48, display 44 to 96 (fluid). Headings are one weight and one colour; no accented word inside a heading.
- **Young step:** body 19, lead 21, title 26, leading 1.7, measure 52ch. **Dense step:** body 15, leading 1.45.
- Tshivenda's dotted and circumflex-below letters fall back to the system face; legible, not a perfect match.

## Layout

A 4px spacing scale (4 to 128). One container capped at 1344px, centred; gutters grow from 16px on phones to 32px and 48px. Sections on public pages sit 96 to 128px apart; signed-in pages use the page head (title, one sentence, actions) and panels that share edges. Tables before cards; numbers right-aligned. Every region that loads data has empty, loading, success and error states.

Signed-in shells: a rail of destinations (a drawer below 960px) and a top bar; the learner shell adds a four-item bottom bar on phones. Navigation lists only screens that exist. The young step shows fewer items and plainer labels.

## Elevation & Depth

Depth comes from tone steps and from flat planes overlapping, never from blur at rest. Only what truly floats carries the one float shadow: menus, the combobox list, dialogs, toasts, and interface fragments laid over a photograph on the landing.

## Shapes

Labels and controls have a 4px corner, like a spine label (8px in the young step). Language chips are fully round so a language reads apart from a level. Panels, sections and media are square. Buttons are 44px tall (56px young, 36px dense) with a 44px hit area.

## Components

The kit is Blade components under `resources/views/components/td/` (`x-td.button`, `x-td.field`, `x-td.language-chip`, `x-td.pill`, `x-td.verify-badge`, `x-td.money`, `x-td.progress`, `x-td.state` and the rest), documented with usage in `docs/R47_DS.md`. The shells are `<x-layouts::public>`, `<x-layouts::learner>`, `<x-layouts::workspace>` and `<x-layouts::operations>`.

- **Verification badge:** verified, in review, self-declared, lapsed, not verified; a verified peer tutor stays neutral grey so it never reads as a professional credential.
- **Language chip:** the language in its own name first (isiZulu, Afrikaans), its BCP 47 code, the role it plays (teaches in, understands, learning) and a verified mark only after a real check.
- **Money:** integer minor units only; R1 250.00 style with a narrow space between thousands.
- **Match reasons:** every dimension the matcher checked, met or not; gaps are shown, never hidden.

## Do's and Don'ts

- Do use tokens through the components or the `td-` Tailwind colours; never a literal colour or font.
- Do label sample, example and machine-translated content as such.
- Do keep motion to state changes (120 to 200ms ease-out) and the landing's one headline rise; honour reduced motion.
- Don't show a figure the product did not measure; print a dash with the reason instead of a zero.
- Don't use a stage colour for decoration, a gradient, glass, a coloured side stripe on a card, an eyebrow label or tracked capitals.
- Don't load anything from another host: fonts, icons (the sprite in `public/icons`) and scripts are self-hosted so the security headers can enforce a nonce CSP.
