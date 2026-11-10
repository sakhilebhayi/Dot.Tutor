# R-47 design system (ds agent)

One identity for Dot.Tutor and the kit every wave-2 screen is built from. Visual rules and token values: `DESIGN.md` (Google format, lint clean). Product context the design skills read: `PRODUCT.md` (inferred from the brief; the owner was not interviewed, per the standing "no questions" order). Decisions D-100 to D-117 in `docs/R47_DECISIONS.md`; skills record in `docs/R47_SKILLS.md`.

## 1. The idea

Dot.Tutor is a lending library of people. Every academic level has its shelf, every subject its room, and every tutor a catalogue record that says what they teach, at which levels, in which languages and what was verified. The world is a reading room: shelf-label white tinted toward reading-room green (never cream), deep green-black ink, one reading-room green for actions, and eight binding colours that mark the academic stage. Stage colours are data, never decoration.

## 2. Build and assets

- Tailwind 4.3.1 through `@tailwindcss/vite` (Vite 8, laravel-vite-plugin 3). `tailwind.config.js` and `postcss.config.js` are gone; configuration lives in `resources/css/app.css` (`@source` lists exactly the Blade views, compiled views and Jetstream/pagination vendor views; `@plugin '@tailwindcss/typography'` for the policy pages).
- CSS: `resources/css/ds/{fonts,tokens,base,components,shells,landing,legacy}.css`, imported by `resources/css/app.css`. JS: `resources/js/ds/*.js`, imported by `resources/js/app.js`. Built size about 25 KB CSS and 3 KB JS gzipped.
- Fonts, self-hosted in `public/fonts` (OFL 1.1, licence texts beside them): Archivo (display, instanced to width 62-100 and weight 500-800 and subset with fontTools) and Atkinson Hyperlegible Next (text, latin, latin-ext and italic). Latin-ext files load only when a page uses those characters. Tshivenda's dotted and circumflex-below letters come from the system fallback.
- Icons: one SVG sprite, `public/icons/td-sprite.svg`, 68 Lucide icons (ISC licence, `public/icons/LICENSE-lucide.txt`). Add an icon: put its name in `ICONS` in `resources/js/ds/build-sprite.mjs` and run `node resources/js/ds/build-sprite.mjs <unpacked lucide-static>`. Use it with `<x-td.icon name="search" />`.
- No runtime CDN anywhere I own: no Google Fonts, Material Symbols, unpkg Alpine or cdn.tailwindcss.com in any layout, error page or the landing (`tests/Feature/DesignSystemKitTest.php` fails if one returns). Alpine comes only from Livewire's bundle.
- Two inline scripts remain and both read the Vite CSP nonce when one is set (`Vite::cspNonce()`): the pre-paint theme script in `layouts/partials/head` and the drawer script in `layouts/partials/drawer-script` (the shell test contract requires the drawer script inline). The security agent can enforce a nonce CSP once it calls `Vite::useCspNonce()`.

## 3. Tokens

All `--td-*`, in `resources/css/ds/tokens.css`, OKLCH, light by default, night under `prefers-color-scheme: dark` unless the person picked light, and under `:root[data-theme="dark"]`. Tailwind utilities get them as `bg-td-surface`, `text-td-ink`, `border-td-line`, `font-td-display` and so on (`@theme inline` in `app.css`).

| Group | Tokens | Use |
|---|---|---|
| Surfaces | `ground`, `surface`, `sunken`, `raised`, `line`, `edge`, `deep`, `deep-ink`, `scrim` | Tone steps separate regions; `edge` is for field and control outlines (3:1); `deep` is the footer and inverse bands (dark in both themes) |
| Ink | `ink`, `ink-2`, `ink-3`, `ink-inverse` | Running text is always `ink`; `ink-2` for secondary lines (4.5:1); `ink-3` icons and large text only |
| Brand | `primary`, `primary-hover`, `primary-press`, `primary-ink`, `primary-soft`, `primary-soft-ink` | Primary action, current place, selection, links |
| Focus | `focus`, `focus-halo` | A 2.5px ink outline with a ground-coloured halo, instant |
| Signals | `success|warning|danger|info` with `-soft` and `-ink` | Soft ground plus an ink that reads 4.5:1 on it |
| Stages | `stage-{foundation,intermediate,senior,fet,undergraduate,postgraduate,doctoral,professional}` and `-ink` | Binding colour per academic stage key (seeded stage keys; honours, pg_diploma and masters map to postgraduate) |
| Type | `font-display`, `font-text`, `display-stretch`, `text-xs..text-display`, `leading*`, `measure` | 13/14/16/18/22/28 plus fluid section and display sizes |
| Space | `space-1..space-10` (4px base), `gutter`, `container` (1344px) | |
| Controls | `control-h`, `control-pad-x`, `control-text`, `row-pad-y`, `row-pad-x` | Changed by the steps, never by components |
| Shape | `radius` (4px), `radius-pill` | Panels and media are square |
| Motion | `ease-out`, `dur-micro` (120ms), `dur-short` (200ms), `dur-reveal` (640ms) | State only; all motion is cut under reduced motion |
| Layers | `z-sticky`, `z-drawer`, `z-dialog`, `z-toast` | |

## 4. The steps: one system from Grade 1 to doctoral

`data-td-step` on `<html>` (the shells set it from their `step` prop) or on any container:

- **young** (Foundation and Intermediate phases, and any learner who asks for it): body 19px, leading 1.7, 56px controls, 8px corners, 52ch measure, thicker progress bars. The learner shell hides items marked for standard and dense (Courses, Messages, Payments) and relabels "Find a tutor" as "Get help". Wave 2 decides when to set it (from the learner's stage via the academic seam and the age band via the safety seam); the design system never infers age.
- **standard**: the default.
- **dense** (business step: tutor workspace tables, operations queues): body 15px, 36px controls, tight rows (for desk use with a pointer; screens a learner taps on a phone stay standard). The operations shell defaults to it; `<x-td.table dense>` applies it to one table.

## 5. Components (`resources/views/components/td/`)

| Component | Usage | Notes |
|---|---|---|
| `x-td.icon` | `<x-td.icon name="search" />`, `label="..."` when it is the only content | Decorative by default |
| `x-td.button` | `variant` primary, secondary, quiet, danger; `size` sm, md, lg; `href`; `icon`, `icon-end`, `icon-only`; `loading`; `disabled`; `block` | Loading sets `aria-busy` and disables; a disabled link is `aria-disabled` |
| `x-td.field` | `label`, `for`, `hint`, `error`, `optional`; wraps a control | Hint and error ids are `{for}-hint` and `{for}-error` |
| `x-td.input`, `x-td.textarea`, `x-td.select` | `name`, `id`, `value`, `hint` (bool, adds the hint id to `aria-describedby`); select takes `options` and `placeholder`, groups via `group` | `aria-invalid` from the error bag; `old()` values |
| `x-td.combobox` | `name`, `options` (`value`, `label`, optional `hint`, `search`) | ARIA 1.2 list autocomplete; without script a native select with the same name submits instead |
| `x-td.check` | checkbox or radio with its label | |
| `x-td.language-chip` | `code`, `name`, `endonym`, `role` (teaches, understands, learning, interface, prefers), `verified`, `match`, `pressable`, `pressed` | Own-language name first, `lang` attribute set; verified only after a real check |
| `x-td.pill` | `kind` stage (binding colour), level (swatch), subject (outlined); `stage` key | |
| `x-td.verify-badge` | `status` verified, review, self, lapsed, rejected; `level` peer, independent, qualified, professional, institution | Glyph and words for every state; a verified peer tutor is neutral grey |
| `x-td.money` | `minor` (integer), `currency`, `per` | Never pass a float; `R1 250.00` style |
| `x-td.avatar` | `name`, `src`, `size`, `decorative` | Initials on a stable pale tint per name |
| `x-td.state` | `kind` empty, loading, success, error; `title`; slot; `actions` slot | Every data region needs all four |
| `x-td.skeleton` | `lines` or `height` | Shape of what is coming |
| `x-td.progress` | `label`, `value`, `max`, `display`, `stage` | `role=progressbar`; the figure is always printed |
| `x-td.tabs` + `x-td.tab-panel` | items with `href` (navigation, state in the URL; preferred) or `panel` (ARIA tabs, `resources/js/ds/tabs.js`) | |
| `x-td.dialog` | `id`, `title`, `sheet`, `dismissible`; `footer` slot; open with `data-td-dialog-open="id"` | Native `<dialog>`; a bottom sheet on phones |
| `x-td.toasts` | Once per shell (the shells include it) | Flash `flash.banner`, `success`, `error`; `window.tdToast(message, tone)` and Jetstream's `banner-message` event; only after the server confirmed |
| `x-td.pagination` | `paginator`, `label` | Summary in words plus a page window |
| `x-td.table` | `caption` (required, names the scroll region), `caption-hidden`, `dense`; thead/tbody in the slot; `is-num` cells | Labelled `table-scroll` region |
| `x-td.stat-strip` | `items` (`label`, `value`, `hint`) | Shared edges, never tiles; unknown values print a dash and a reason |
| `x-td.timeline` + `x-td.timeline-item` | `title`, `state` done, current, upcoming, `meta` | |
| `x-td.file-picker` | `name`, `label`, `hint`, `accept`, `multiple`, `max-kb` | A real file input; lists choices, flags oversize files before upload; the server validates |
| `x-td.notice` | `tone` neutral, info, success, warning, danger; `title` | Tinted ground, never a side stripe |
| `x-td.panel` | `title`, `flush`, `heading-level`, `actions` slot; group in `<div class="td-panels">` (`td-panels--2` for 2:1) | The one container |
| `x-td.page-head` | `title` (the page h1), `lede`, `actions` slot | |
| `x-td.match-reasons` | `reasons` (`text`, `met`) | Unmet lines are shown |
| `x-td.theme-switch` | | Device, light, dark; stored on the device only |

`resources/views/layouts/kit.blade.php` shows every component in its states inside the learner shell (sample data, labelled). It has no route; `tests/Feature/DesignSystemKitTest.php` renders it (set `DS_DUMP_KIT=1` to write the HTML to `public/__ds/kit.html` for a look; do not commit it).

The Jetstream base components (`button`, `secondary-button`, `danger-button`, `input`, `label`, `checkbox`, `form-section`, `action-section`, `section-title`, `input-error`, `validation-errors`, `action-message`, `section-border`, `dropdown`, `dropdown-link`, `modal`, `dialog-modal`, `confirmation-modal`, `authentication-card`, `authentication-card-logo`) now render with the kit's classes, so profile, team, API token and auth pages are in the system without page edits.

## 6. Shells (`resources/views/layouts/`)

Registered as anonymous components under the `layouts` namespace by `App\Providers\TutorDesignServiceProvider`.

| Shell | Use | Props |
|---|---|---|
| `<x-layouts::public>` | Landing, policies, any signed-out page | `title`, `description`, `credits` (photo credits for the footer) |
| `<x-layouts::learner>` | Everything a learner (and a guardian, until it has its own) does | `title`, `step` (default standard), `livewire`; slot `top` replaces the top-bar title |
| `<x-layouts::workspace>` | The tutor's practice | same |
| `<x-layouts::operations>` | Operators | same; default step dense |
| `<x-app-layout>` (`layouts/app`) | Existing Jetstream pages, bridged | unchanged API (`header` slot) |
| `<x-guest-layout>` (`layouts/guest`) | Sign-in, password, two-factor, policies | unchanged API |

All signed-in shells share `layouts/partials/frame`: skip link, a rail of destinations (a drawer below 60rem, readable in the page flow without script), the top bar with the menu button, theme switch and account link, `main#main-content`, toasts, and the drawer script. The learner shell adds a bottom bar on phones (up to four items marked `bottom`). The markup hooks that `tests/Concerns/AssertsResponsiveShell` checks are kept: `#app-sidebar`, `[data-shell-open]` labelled Menu, `[data-shell-close]` labelled Close menu, `.shell-backdrop`, `[data-shell-behind]`, `.sidebar-footer` with sign-out and the team switcher.

### Navigation: route names that light up a shell

`config/tutor_nav.php`, resolved by `App\View\ShellNavigation`. An item names candidate routes in order; the first that exists is linked, and an item with none is not shown (no dead links). It is current when the request matches its `match` pattern(s). Wave-2 agents light up a place by registering the route name; no layout edit is needed.

- **learner**: `learn.home` (falls back to `dashboard`), `match.index` (falls back to `tutors.browse`), `rooms.index`, `learn.lessons`, `learn.courses`, `library.index`, `practice.index`, `learn.progress`, `messages.index`; You: `learn.profile`, `guardian.home`, `money.orders`, `profile.show`. Bottom bar: Home, Find a tutor, My rooms, Lessons.
- **workspace**: `teach.home` (falls back to `dashboard`), `teach.bookings`, `teach.calendar` (falls back to `tutor.availability.index`), `teach.students`, `messages.index`; practice: `teach.offerings`, `teach.courses`, `teach.materials`, `teach.rooms`, `teach.profile` (falls back to `tutor.profile.edit`); money: `teach.earnings`, `teach.payouts`, `teach.insights`.
- **operations**: `ops.home` (falls back to `dashboard`), `ops.verification` (falls back to `operator.tutor-profiles.index`), `ops.reviews`, `ops.moderation`, `ops.safeguarding`, `ops.audit`, `ops.academic`, `ops.subjects` (falls back to `operator.subjects.index`), `ops.languages`, `ops.payments`, `ops.payouts`, `ops.users`, `ops.support`.

Shells do not authorise anything: route middleware and policies do.

## 7. The legacy bridge

`resources/css/ds/legacy.css` maps the old pages' own `<style>` classes and inline zinc colours onto tokens under `.td-app--app` and `.td-guest-body`, so the dashboard, tutor browse and profile, session, availability, tutor profile and operator pages read correctly in light and dark today. Each block names the page it serves; delete a block when wave 2 moves that page to a shell, and delete the file when none remain. The hidden Livewire `navigation-menu` stays inside `.shell-legacy-nav` only because `ResponsiveShellTest` still asserts it; its sign-out and team switcher live in the rail footer.

## 8. Landing (`welcome.blade.php`)

Sections: the first screen (headline, one sentence, Find a tutor and Teach on Dot.Tutor, a UCT library photograph with an example request slip); the stage shelf (eight books, one per stage, a one-open accordion on wide screens, stacked on phones, all open without script); how matching explains itself (the request set large with an example explanation over it); who it is for (learners, tutors with the verification ladder, guardians); languages in their own names with codes; a close with both actions; the footer with photo credits. No tutors, counts, reviews or logos. "Find a tutor" goes to `match.index` when that route exists, otherwise `tutors.browse` (sign-in first).

Claims the landing makes that later agents must keep true before deploy (QA, D-1800): Cambridge on the same shelves (academic, D-3); teaching-language matching, never the interface language (marketplace); guardian approval of bookings, payments and rooms for under-18s and private contact details (safety); reporting from every page (safety and community via `ReportSink`); machine translation labelled with the original one tap away (academic translations, ai); Portuguese, French and Swahili seeded (academic); the five verification levels (marketplace).

Photographs (`public/images/landing`, WebP, 60 to 95 KB, lazy below the fold): The Hub, Chancellor Oppenheimer Library, UCT (Theresa Schoeman, CC0); Johannesburg New Library 1935 (public domain); South African Library, Cape Town, about 1900 (public domain); Book spines, Town Library of Ipswich (Leutha, CC BY-SA 4.0; kept because no openly licensed South African shelf photograph of that quality was found; replace when one is). Credits print in the footer.

Error pages (`resources/views/errors`): the same header, a shelf with one book missing, plain-language messages without dashes, and the Dot ecosystem links. The layout reads the Vite manifest itself so it renders even when the app is half broken.

## 9. Checks run

- `php artisan test --compact`: 298 tests, 291 pass, 7 skipped (baseline 293/286/7; +5 in `DesignSystemKitTest`; `SingleAlpineTest` updated to read the frame partial where `@livewireScripts` now lives).
- `npm run build` clean. `npx @google/design.md lint DESIGN.md`: 0 errors, 0 warnings.
- `impeccable detect`: no findings. Hallmark `sloplint`: 0 FAIL (render tier not run: no puppeteer-core installed). Taste `lint`/`inspect`: remaining items listed in `docs/R47_SKILLS.md` with reasons.
- Browser (own headless Chrome over CDP, since the shared browser pane was at its tab cap): landing, 404, kit, dashboard and profile at 390 and 1440 in light and dark; keyboard focus on hero buttons and shelf spines; no horizontal overflow at either width.
- Impeccable finish reviewer (fresh subagent): disposition **fix**, eight material fixes; all eight applied (D-115). A verdict pass on the fixes was not run.

## 10. Not done, or left to others

- The wave-2 screens themselves, and the decision of when to set the young step (needs the academic and safety seams).
- The `match.index` live request field on the landing hero (the hero shows a labelled example until the marketplace route exists; then swap the slip for a real form posting to it).
- A Paginator default view: pages call `<x-td.pagination :paginator="...">` explicitly.
- Enforcing the CSP (security, wave 3); removing the legacy bridge and `navigation-menu` (wave-2 UI agents).
