<div align="center">

<img src="public/images/logo.png" alt="Dot.Tutor" width="220" />

<br /><br />

**Find expert tutors, book sessions, and learn online or in person.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13.8-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `tutor.infodot.co.za`

</div>

---

## What is Dot.Tutor?

Dot.Tutor is the tutoring/education platform in the InfoDot ecosystem: tutors list subjects and an hourly rate, students book sessions, and both sides can leave a rating and review once a session completes. It is a Laravel 13.8 / Jetstream 5 / Livewire 3 app with a working `EcosystemAuthController` SSO handoff, matching the contract used across the rest of the ecosystem.

> **Note:** this README previously described features — AI session summaries, AI-generated learning paths, a video-call virtual classroom, an earnings/payout dashboard, Meilisearch search, S3 storage, Redis/Horizon queues — that do not exist in this codebase. `composer.json` has no Anthropic/AI, Scout, or Horizon dependency, and no such service classes exist under `app/`. See `wiki.md` for what is actually built vs. still ahead.

## Core Features (as implemented)

- Tutor directory data model — `TutorProfile` with bio, hourly rate, rating, status (`pending`/`approved`/`suspended`), and a many-to-many link to `Subject`
- Session booking data model — `TutorSession` with status (`pending`/`confirmed`/`completed`/`cancelled`), delivery mode (`online`/`in_person`), start time, duration, and rate
- Post-session lesson resources (`LessonResource`) and one rating/review per session (`SessionRating`)
- An internal ops dashboard (`/dashboard`) with platform-wide KPIs (total/upcoming/completed sessions, approved tutor count, revenue) and session lists scoped to the signed-in user's own bookings
- Ecosystem SSO from the InfoDot hub (`/auth/ecosystem`, Sanctum token handoff)
- Jetstream Teams, 2FA, profile management (framework defaults)

**Not implemented** (present only as unused env vars, aspirational docs, or not present at all): AI session summaries, AI learning-path generation, video calling / whiteboard, tutor earnings & payout tracking, booking UI/controllers, search, S3 storage, Redis/Horizon queues. There is no controller or Livewire component yet for browsing tutors or booking a session — the domain tables and models exist, but the booking flow itself is unbuilt.

## Domain Models (as implemented)

- **TutorProfile** — a user's tutor listing: bio, hourly rate, rating, subjects, status
- **TutorSession** — a booked session between a `TutorProfile` and a student (`User`)
- **Subject** — a bookable subject/level combination
- **LessonResource** — a file attached to a session by either party
- **SessionRating** — a single rating + review per completed session

## Responsive app shell

Signed-in pages render inside `resources/views/layouts/app.blade.php`. From 860px up it is the fixed 260px sidebar and top bar, as before. Below 860px the sidebar becomes an off-canvas drawer that the **Menu** button in the top bar opens (`#app-sidebar`, `[data-shell-open]`, `[data-shell-close]`). `layouts/partials/shell-script.blade.php` moves focus into the drawer, closes it on Escape or a backdrop click, locks page scroll, makes the page behind it inert and returns focus to the button. It is plain JavaScript, no Alpine or build step.

- Wide tables go in a `<div class="table-scroll" role="region" aria-label="..." tabindex="0">` so only the table scrolls sideways.
- Page markup is mostly inline styles, so the layout carries a few phone-only attribute-selector rules (page padding, grids collapse to one column under 640px, 44px controls, 16px field text so iOS does not zoom). New pages should prefer classes and `minmax()` grids over fixed widths.
- The Jetstream `navigation-menu` bar that sits under the fixed top bar is wrapped in `.shell-legacy-nav` and hidden on phones. Its team switcher and sign-out now live in the drawer footer.
- `tests/Feature/ResponsiveShellTest.php` asserts the markup hooks above. It checks the rendered HTML, not pixels, so layout still needs a look in a browser at 360, 375, 768 and 1280px.

## Tech Stack

| Layer | Technology | Status |
|---|---|---|
| Framework | Laravel 13.8, PHP 8.3+ | Installed |
| Frontend | Livewire 3 · Alpine.js (CDN) · Tailwind (CDN) | Installed |
| Database | PostgreSQL (shared `infodot` instance) | `DB_DATABASE=infodot` in `.env.example`, matches ecosystem convention |
| Auth | Laravel Sanctum + Jetstream/Fortify (teams, 2FA) | Installed; `EcosystemAuthController` verified against the ecosystem SSO contract |
| Realtime | Laravel Reverb | Dependency present, not wired to any domain broadcast |
| AI | — | Not integrated. `ANTHROPIC_API_KEY`/`ANTHROPIC_MODEL` exist in `.env.example` but no service class reads them |
| Storage | Local (Flysystem) | AWS S3 not configured |
| Search | — | Laravel Scout not installed |
| Queue | Database queue driver | Redis/Horizon not installed |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Tutor.git
cd Dot.Tutor
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and `APP_URL` to this platform's real ecosystem hostname. Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens against `/auth/ecosystem`.

### Running Tests

```bash
php artisan test
```

Feature tests use an in-memory SQLite connection (see `phpunit.xml`) and Laravel's `RefreshDatabase` trait — no shared Postgres instance required to run them. **This environment has no PHP/Composer available, so tests added in the Aug 2026 ecosystem-integration pass are written to the repo's existing standard but have not been executed here** — see `wiki.md` and `Dot.Brain/os/02-Engineering-Loop.md` §2.

## Metrics for Dot.Analytics

Once a day (03:00 UTC, `tutor:publish-metrics`) this app queues one signed snapshot per company to Dot.Analytics'
server-to-server ingest (`POST /api/ingest/snapshots`) through `App\Services\Analytics\AnalyticsClient` and
`App\Jobs\SendAnalyticsSnapshot`.

**Which company.** Dot.Tutor is a user-scoped marketplace: sessions and tutor profiles have no team of their own.
A company is a non-personal team linked to InfoDot (`teams.infodot_team_uuid`), and a session or tutor counts for
the company that is its **tutor's current team** at the time of the run (the student's team is irrelevant, and a
tutor who switches team takes their history with them). A tutor whose current team is personal or not linked
belongs to no company and is not counted anywhere; there is no platform-wide fallback.

What is sent, and nothing else (Dot.Analytics' `tutor` catalogue; counts only, never a name, email, note or row,
and no money, so no `currency`). Windows are UTC, the 7 days ending when the command runs:

| Key | Meaning |
|---|---|
| `sessions_7d` | sessions booked (created) in the last 7 days, whatever became of them since |
| `completed_7d` | sessions that were marked completed in the last 7 days. There is no `completed_at` column; a completed session is never edited again, so its `updated_at` is the completion time |
| `active_tutors` | approved tutor profiles right now |

The snapshot is stamped `taken_at` = the start of the UTC day, so a retry or a second run the same day is the same
snapshot upstream (Dot.Analytics keeps the first one it received for a day).

Nothing is sent until `ANALYTICS_INGEST_URL` (the full URL ending `/api/ingest/snapshots`) **and**
`ANALYTICS_INGEST_SECRET` are set; the secret is the same value as `ANALYTICS_INGEST_SECRET_TUTOR` in
Dot.Analytics' `.env`, and `ANALYTICS_PLATFORM_KEY` defaults to `tutor`. Requests are HMAC-SHA256 signed over
`"{timestamp}.{raw body}"` at send time, time out after 5 seconds, are retried up to 3 times with backoff, never
throw into the app, and stop for 5 minutes (circuit breaker) after 5 failures in a row, so an Analytics outage or a
wrong URL cannot affect Dot.Tutor. This needs the scheduler (`* * * * * php artisan schedule:run`) and a queue
worker (`php artisan queue:work`). Try it safely with `php artisan tutor:publish-metrics --dry-run` (prints the
payloads, sends nothing) and `--company=<uuid>`.

## Ecosystem

**Dot.Tutor** is one of the platforms in the InfoDot ecosystem, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
