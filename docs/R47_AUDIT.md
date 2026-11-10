# R-47 audit: Dot.Tutor as found on 2026-11-10

Method: read every route, controller, service, model, enum, migration, config file and view; ran the suite (293 tests: 286 pass, 7 skipped, 0 fail, SQLite in memory); built the frontend; probed the live host (`/`, `/login`, `/up` answer 200; `/register`, `/dashboard`, `/tutors` redirect to the login; the last deploy, 2026-10-31, was green). Domain agents extend this file with a detailed audit of their own area under "Domain audits".

Classes: **F** fully functional, **I** functional but needs improvement, **P** partially implemented, **V** UI-only or simulated, **B** broken, **M** missing but essential.

## Platform areas

| Area | Class | Finding | Root cause |
|---|---|---|---|
| Public landing | I | One marketing page ("Book a tutor who actually teaches your subject"), Fraunces and Figtree from Google Fonts, Alpine from unpkg | Built as a brochure for a booking tool; CDN dependencies |
| Registration and sign-in | F | InfoDot signed redeem (POST body token), team sync, local registration closed, 2FA, sessions; tested | n/a (kept) |
| Student profile | M | A user row only: no country, curriculum, level, subjects, languages, age or guardian | No learner model |
| Tutor profile | I | One application per user: headline, bio, qualifications as free text, hourly rate (decimal), currency label, timezone, subjects; operator approves, rejects, suspends | Qualifications are unverified text shown as fact; no levels, curricula, languages, formats or offerings |
| Tutor onboarding | P | A single form then a wait for the operator | No guided steps, no evidence upload |
| Tutor verification | P | Operator review of the profile as a whole (approve/reject/suspend with reasons, notifications) | No evidence, no levels of verification, peer and professional tutors indistinguishable |
| Academic levels and grades | B (vs brief) | `subjects.level` is a free string defaulting to `high_school` | No academic structure: no countries, curricula, grades, tertiary levels |
| Subjects | I | Operator-managed catalogue with activate/deactivate; seeded list | Not tied to curriculum or level |
| Tutor discovery and search | P | `/tutors` lists approved tutors, filter by one subject, ordered by rating | Popularity ordering; no level, curriculum, language, budget, availability or format |
| Matching | M | None | Not built |
| Booking and scheduling | I | Weekly availability windows in the tutor's timezone, blocked dates, a slot finder over 14 days, 30-minute grid, overlap guard, pending, confirmed, completed, cancelled, no-show (stale pending sessions expire), throttled | Good core; no group sessions, recurrence, packages, guardian approval or payment step |
| Online learning | M | `delivery` is a label (online or in person); no classroom, no link, no video | Not built |
| Learning rooms and communities | M | None | Not built |
| Courses | M | None | Not built |
| Study materials | P | Files attached to one session (upload, download, delete) for its two participants | Session-scoped only; no library, metadata, licensing or moderation |
| Payments and commissions | M | Rates are stored as decimals and shown as labels; config says "Dot.Tutor does not take payment yet" | Payment provider decided later (now Paystack, per the owner, 2026-10-18) |
| Earnings and payouts | M | None | Not built |
| Reviews and ratings | I | One rating per completed session by the student, 1-5 with a range check, optional review, average on the profile | No moderation, no verified-booking label beyond the session link, no tutor reply |
| Messaging | M | None (only session notes) | Not built |
| Notifications | P | Database notifications for profile decisions and moderation cancellations, a dashboard list, mark read | No email, push or preferences; no booking reminders |
| Assessments and progress | M | None | Not built |
| Admin and moderation | P | Operator flag on users; tutor profile review queue; subject management; dashboard KPIs scoped by role | No safeguarding, content moderation, finance, academic structure or reports |
| Multilingual | M | English only; no lang files; no language data on users, tutors or content | Not built |
| Child safety | M | Every user treated as an adult; no age, guardian, contact restrictions or reporting | Not built |
| Database | I | 19 migrations; decimals for money; history protected from cascading deletes (2026-10-18); rating range check | Small, sound base to extend additively |
| Integrations | I | InfoDot SSO and team sync; Dot.Analytics daily snapshot (`PublishMetrics`); Reverb installed but unused | No Notify, Files, Billing, AI |
| Security | I | Enforced baseline headers, report-only CSP (the signed-in layout loads Tailwind from cdn.tailwindcss.com, Google Fonts and Material Symbols), policies on sessions and resources, throttles on bookings and uploads | CDN dependencies block an enforced CSP |
| Visual design | B (vs brief) | Two identities: a Fraunces landing and a dark Syne/Inter dashboard with Material Symbols, Tailwind from a CDN | No design system |
| Tests | I | 293 tests, good on booking rules, lifecycle integrity, SSO, health, headers | No browser tests, no PostgreSQL run |
| Deploy | F | Manual `deploy.yml` (adopt and deploy modes, CI gate, Vite build on the runner, smoke), `drift.yml`, `server-logs.yml`; green 2026-10-31 | n/a (kept and extended) |

## Preserved

InfoDot sign-in, team sync, closed registration, the scheduling core (availability windows, blocked dates, slot finder, overlap guard, expiry command), the tutor review workflow, session ratings, lesson resources (folded into the new library as session-scoped material), the Analytics snapshot, the security headers, the deploy pipeline, and every existing row: all migrations are additive, decimal money columns gain integer minor-unit companions that are backfilled, and the old free-text `subjects.level` is mapped onto the new academic structure without being dropped.

## Domain audits
(appended by the wave-1 agents)

### Design system and public pages (ds agent, 2026-11-10)

| As found | Class | Finding | After |
|---|---|---|---|
| Visual identity | B | Two unrelated looks: a Fraunces/Figtree landing on cream and a dark Syne/Inter dashboard with an amber accent | One world (DESIGN.md): reading-room tokens, Archivo and Atkinson Hyperlegible Next, eight stage binding colours, light and night themes |
| Frontend build | P | `@tailwindcss/vite` 4 installed but unused; Tailwind 3 via PostCSS for guest pages and cdn.tailwindcss.com at runtime for signed-in pages | Tailwind 4.3.1 built by Vite for every page; no runtime CDN |
| Fonts and icons | I | Google Fonts on every layout; Material Symbols icon font from Google | Self-hosted OFL fonts in public/fonts; Lucide SVG sprite in public/icons |
| Alpine | I | unpkg Alpine on the landing (Livewire already bundles Alpine) | Removed; the landing needs no Alpine |
| Component kit | M | Jetstream defaults plus per-page inline styles and `t-*` classes in the layout | 30 `x-td.*` components with states and accessibility built in; Jetstream components restyled |
| Shells | P | One app layout (sidebar drawer, good a11y contract) for everyone | Public, learner (phone bottom bar), tutor workspace, operations shells on one frame; navigation as data, only built routes linked |
| Young learners | M | No difference between a Grade 1 learner and an operator | Young, standard and dense steps in one system |
| Landing | I | A brochure for a booking tool with an Unsplash hotlink and unpkg Alpine | Rebuilt around plain-words requests, explained matches, rooms by level, tutors' practice and languages; real content and credited open-licence photographs |
| Error pages | I | Designed, but loaded Google Fonts and Material Symbols | Same pages in the new system, self-contained, no third-party hosts |
| Existing pages | n/a | Dark inline styles per page | Render in the new system through a temporary bridge (legacy.css) until wave 2 rebuilds them |
