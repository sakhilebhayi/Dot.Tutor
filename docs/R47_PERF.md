# R-47 performance (measured)

Owner: perf/a11y agent (wave 3), branch `feat/r47-perfa11y`, decisions D-1600 to D-1613. Code: `App\Domain\Perf\*`
(`TutorPerfServiceProvider`, `LazyTouchDatabaseSessionHandler`, `LeanPollResponses`, `TutorInvariants`), the index
migration `2026_11_12_200000_add_r47_perf_lookup_indexes`, `scripts/perf/*`, `tests/Feature/Perf/*`. Fixes in other
owners' files are listed in section 7. Tooling reused and adapted from Dot.Ehail R-46 (`R46_PERF.md`).

Every number was measured on this machine (Apple M4, 10 cores; PHP 8.5.9 with OPcache; SQLite 3.53; PostgreSQL 16 on
localhost TCP). What the shared host supports is an **estimate** from those measurements (section 3.4) and is labelled.

## 1. The perf world and the probes

| Piece | What |
|---|---|
| `scripts/perf/world.php setup` | Fresh schema, `academic:sync`, every `Demo*` seeder, then bulk volume: 4,000 learners (Grade 1 to postgraduate, profiles, subjects, languages, age records), 400 approved tutors (scopes, teaching languages, offerings, open hours), 24,000 lessons over 180 days back and 21 ahead (no tutor double-booked), 7,180 reviews, 12,000 paid orders with lines, earnings and balanced `order_paid` journals (36,000 entries), a Grade 10 Maths room with 1,500 questions and 4,500 answers and 1,200 members, a 300-lesson course with 2,500 enrolments (the matric learner half way), 200 more courses, 600 inbox notices. 11 s. Refuses unless `APP_ENV` is local/testing and the database is a `*perf*` SQLite file or a `tutor_perf_*` PostgreSQL database. |
| `scripts/perf/pages.php` | 41 pages and polls through the full HTTP stack in-process (minted database sessions, every middleware, Blade): median time, statements, writes, DB time, bytes, and the most repeated statement shape with its distinct bindings (`--dups`, the N+1 signal). `--explain` runs every distinct SELECT through the planner and lists full scans of tables over 2,000 rows. |
| `scripts/perf/fk-indexes.php` | Every foreign key column no index leads with (180 before the migration, 121 after: the rest are written for the record and never read by). |
| `scripts/perf/load-prep.php`, `load.mjs`, `serve.sh` | Live classrooms (tutor and learner joined, polling every 2 s with the real body and headers), 20 room tabs polling every 30 s with ETags, and a booking storm (every booker asks for one of 30 open slots at the same second, a fifth double-tapping with one Idempotency-Key). PHP's built-in server with N forked workers (`pm=static` shape), OPcache, debug off, database sessions/cache/queue. |
| `scripts/perf/frontend.mjs`, `gzip-proxy.mjs`, `cookies.php` | Throttled phone (section 4). |
| `php scripts/perf/world.php invariants` | `TutorInvariants::check()` (section 5). |

`sh scripts/perf/env.sh <cmd>` runs anything against the perf world (`PERF_DB=pgsql PERF_PG=tutor_perf_x` for PostgreSQL).

## 2. Server cost of the heaviest pages

### 2.1 Before and after, PostgreSQL (medians of 5 runs; the "after" PostgreSQL run predates the security merge)

| Page | Before: ms / statements | After: ms / statements | What changed |
|---|---|---|---|
| Matching, subject only (300 candidates) | 785 / **1,219** | 262 / 35 | D-1602 |
| Matching, Grade 12 Maths in isiZulu | 335 / 573 | 200 / 45 | D-1602 |
| My bookings (learner) | 97 / 262 | 30 / 16 | D-1603 |
| Ops people list | 44 / 237 | 16 / 16 | D-1604 |
| Ops overview | 410 / 96 | 95 / 79 | D-1605 |
| Tutor insights | 400 / 20 | 50 / 19 | D-1605 |
| Tutor students (2,400 learners) | 225 / 19, **1,317 KB** HTML | 45 / 22, 49 KB | D-1606 (paged) |
| Tutor booking requests (126 open) | 116 / 18, 332 KB | 33 / 18, 85 KB | D-1606 (paged) |
| Learner home | 31 / 41 | 26 / 31 | D-1603 |
| Course player (lesson 151 of 300) | 35 / 19, 188 KB | 33 / 18, 131 KB (12 KB gzipped) | D-1611 |
| Classroom poll, calm | 8.4 / 26, 4 writes | 11.3 / 25, 3 writes | D-1608 (writes) |
| Room activity, unchanged (304) | 10.0 / 20, 2 writes | 9.1 / 16, 1 write | D-1608 |

SQLite, final (trunk merged, `storage/perf/pages-after-sqlite.txt`): matching 196 / 246 ms (45 / 35 statements),
my bookings 23 ms / 16, ops users 12 ms / 20, ops overview 79 ms / 79, insights 40 ms / 19, students 24 ms / 22,
course player 34 ms / 18, classroom poll 3.0 ms / 25 statements / 3 writes, room 304 2.8 ms / 17 / 1. Every other page
of the 41 is under 45 ms. Before (`pages-before-sqlite.txt`): matching 377 ms / 1,219, ops overview 300 ms, insights
381 ms, students 164 ms.

### 2.2 The N+1s and hot paths fixed

- **Matching (D-1602).** Per candidate it read open hours, blocked dates, booked lessons, teaching languages and the
  curriculum name (four to five statements each, 300 candidates). Now one eager load for hours and blocked dates, one
  query for every candidate's booked lessons (grouped by tutor and handed to `SlotFinder`), one for every candidate's
  teaching languages (`BatchLanguageLookup`, implemented by the integration adapter), memoised curriculum names. The PHP
  side was the other half: `SlotFinder::startTimestamps()` returns plain timestamps (the matching loop counted
  thousands of Carbon objects), and the window test uses native `DateTimeImmutable`. Same results: the marketplace,
  scheduling and booking suites (108 tests) pass unchanged.
- **Lists that presented rows one by one (D-1603).** My bookings eager-loads what `BookingPresenter` reads (tutor,
  user, subject, offering, events); `ReviewService::canReview` uses loaded reviews; the learner agenda loads them.
- **People list (D-1604).** `AgeResolver::prime()` and `AccountStanding::prime()` read every listed person's birth
  dates, guardian links, consents, suspensions and (after the security merge) the attested-adult evidence in a handful
  of queries; `BatchBirthDateSource` lets the learner-profile source answer in bulk.
- **Dashboards in PHP (D-1605).** The ops overview walked every lesson ever with a Carbon object per row and offset
  pagination; now one grouped first-lesson query, keyset pagination over the window only, timestamp buckets. Tutor
  insights converted each request twelve times; now once.
- **Unbounded lists (D-1606).** Students and booking requests are paged (50 and 25).
- **Indexes (D-1607).** The migration adds the lookups the screens read by: order lines by order, entries by journal,
  earnings by line and by payout, payouts by payee, refunds, receipts, credits, lessons by series/package/offering,
  reviews by rater, scopes and series by tutor, enrolments by learner, course outline, attempts and answers, banks,
  flashcards, materials, resources, room groups and accepted answers, post revisions, the lesson files the classroom
  poll reads every time, consents by guardian link, reports, incidents, moderation actions, messages; plus date windows
  of the overview (lessons created, orders paid, earnings recorded). `--explain` full scans of big tables: 25 before,
  14 after (the remaining ones are the overview's whole-table aggregates and rowid-ordered pages, which are not scans).
- **Repeated reads of the same row** (the viewer's age record read 5 to 10 times on room, guardian and storefront
  pages, 2 to 3 ms on PostgreSQL) were measured and not changed: a request-wide cache of safeguarding facts is a
  safety decision (stale reads inside a request), recorded for the safety owner.

## 3. Polling

### 3.1 Inventory and budget per active person

| Endpoint | Client | Interval | Statements / writes per request (after) | Answer |
|---|---|---|---|---|
| `POST /lessons/{id}/classroom/api/poll` | live classroom (`room.js`) | 2 s calm, 1 s while connecting or after a roster change, 5 s hidden, back-off to 15 s on errors | 25 / 3 (attendance touch, signal purge, rate limiter) on the database cache store; 18 / 2 with the array store in tests | 0.45 KB body + 0.45 KB headers |
| `POST .../signal`, `.../board` | classroom, while negotiating or drawing | bursts | lean as above | |
| `GET /rooms/{room}/activity` | room page, only while the tab is visible | 30 s | 17 / 1 for an unchanged room (304) | ETag/304; 304 rate 50 % in the load run's 60 s window, and every poll of an unchanged room is a 304 |
| `GET /comms/inbox/summary` | **no client polls it** (found; the bell renders on page load) | none | 10 / 1 | |
| Bookings | **no polling** (pages refresh on action) | none | | |

A person in a live lesson makes 30 requests a minute when calm. Each one now writes no session row (the lazy-touch
handler rewrites it at most once a minute, `PollCostTest`) and carries no XSRF cookie, no re-sent session cookie and
no CSP header (`LeanPollResponses`): about 0.45 KB of response headers instead of 2.7 KB. Estimated data for a learner
on a phone (HTTP payload, measured sizes times the interval): about **1.6 MB an hour down** for signalling, against
about 5.7 MB an hour with the old headers; the WebRTC media itself is peer to peer and not counted.

### 3.2 Load runs (4 workers, 60 s of polling plus ramp; every run followed by `invariants`)

| Run | Polls/s | Poll p50 / p95 | Joins p50 | Booking storm (100 bookers, 30 slots, 20 double taps) | Errors | Invariants |
|---|---|---|---|---|---|---|
| SQLite, 50 classrooms, **before D-1610** | 23 | 26 / 109 ms | 670 ms | 18 created, 89 refused (slot taken), **12 × 500** | **"database is locked": 29 % of joins, 11 % of polls** | hold |
| SQLite (WAL, IMMEDIATE, busy 5 s), 50 classrooms | 33 | 20 / 70 ms | 526 ms | 17 created, 6 replayed taps, 97 refused | 0 | hold |
| SQLite, 200 classrooms (400 people) | 123 | 24 / 631 ms | 1.3 s | 18 created, 97 refused | 0 | hold (at the knee) |
| PostgreSQL, 75 classrooms (150 people) | ~70 in the polling window | 33 / 167 ms | 1.1 s | 19 created, 4 replayed, 97 refused | 0 | hold |
| PostgreSQL, 200 classrooms | 97 | 530 / 1,184 ms | 2.7 s | 18 created | 0 | hold (saturated: people polled every ~4 s instead of 2) |

Refusals in the storm are correct: a slot taken by the first request refuses the rest (`slot_taken`), and adjacent
half-hour slots of a one-hour lesson block each other. Double taps with one key answer the same lesson (200
replayed). No run produced a double-booked tutor, an over-full group, a duplicate enrolment or order, or an
unbalanced journal.

### 3.3 Fixed under load (D-1610)

SQLite had no busy timeout and the rollback journal: under four concurrent workers a second writer failed at once.
`busy_timeout` now defaults to 5,000 ms; `DB_JOURNAL_MODE` and `DB_TRANSACTION_MODE` come from the environment
(`.env.example` documents WAL and IMMEDIATE for a SQLite production database; PostgreSQL, the documented engine, is
unaffected).

### 3.4 What the shared host supports (estimate, not measured on the host)

A calm classroom poll costs 11 ms on this machine against PostgreSQL over TCP (25 statements at ~0.3 ms each plus
PHP). Assumptions: a shared core about twice as slow as an M4 core for PHP, PostgreSQL on the same server, 70 %
target utilisation, the database cache store for the limiter.

| CPU allowance (CloudLinux LVE) | Polls/s at 70 % (~23 ms each) | People in calm live lessons (1 poll / 2 s) | One-to-one lessons at once |
|---|---|---|---|
| 1 core | ~30 | ~60 | ~30 |
| 2 cores | ~60 | ~120 | ~60 |
| 4 cores | ~120 | ~240 | ~120 |

Page views are cheap beside that (most pages 10 to 45 ms here). What would raise it (owner decisions, not built):
`CACHE_STORE=file` or Redis for the rate limiter (5 of the 25 statements and 1 of the 3 writes per poll), a longer
calm interval (3 s would add 50 %), and Reverb on a VPS (no polling at all).

## 4. Front end on a throttled mid-range Android

`scripts/perf/frontend.mjs`: system Chrome through playwright-core, 390 x 844 touch phone, CPU throttled 4x, DevTools
Fast 3G (562.5 ms RTT, 1.47 Mbps down) or Slow 3G (2 s RTT, 400 kbps), a cold load per page in a fresh context, 13 key
pages (landing, sign in, learner and young-learner home, matching, storefront, course player, room, classroom
pre-join, tutor home and earnings, ops overview, guardian). The built-in server does not compress, so
`gzip-proxy.mjs` stands in for the host's `mod_deflate` and the cache headers in `public/.htaccess`.

| Page (Fast 3G) | Before: bytes / FCP (uncompressed) | After: bytes / FCP (uncompressed) | After, gzipped as the host would |
|---|---|---|---|
| Sign in | 525 KB / 3.60 s | 293 KB / 2.60 s | 134 KB / 1.44 s |
| Learner home | 384 KB / 2.82 s | 317 KB / 2.61 s | 137 KB / 1.45 s |
| Course player | 538 KB / 3.46 s | 413 KB / 2.76 s | 138 KB / 1.46 s |
| Classroom pre-join | 479 KB / 2.81 s | 412 KB / 2.63 s | 162 KB / ~1.5 s |
| Landing | 547 KB / 3.26 s | 480 KB / 3.14 s | 285 KB / 1.88 s |

Slow 3G, gzipped: learner home first paint 5.9 s with the font preloads, **4.8 s** without (D-1613). Total blocking
time 0 ms on every page. JS per entry: `app.js` 13.8 KB (5.0 KB gzipped) on every page, `room.js` 66 KB (21.4 KB)
only in the classroom; CSS 155 to 178 KB (27 to 30 KB gzipped; the size depends on whether compiled views exist when
it is built, because Tailwind scans them). Fonts are self-hosted woff2 with `font-display: swap` and unicode ranges
(87 KB for the two faces a page uses). The service worker (`comms-sw.js`) is for push only and caches nothing.

Fixed: the 48.6 KB brand mark shown at 32 px (now a 3.2 KB 64 px copy), Livewire's 164 KB script on every guest
page (now only on the two-factor page that needs Alpine), two eager landing photographs below the fold (lazy),
170 KB of indentation in a long course outline (one line per lesson), font preloads competing with the stylesheet,
and no compression or caching rules (added to `public/.htaccess`).

**Budgets** (first view, gzipped, Fast 3G): HTML ≤ 30 KB, CSS ≤ 35 KB, JS ≤ 30 KB per page (classroom ≤ 60 KB),
images ≤ 100 KB above the fold, first paint ≤ 2 s; no long task over 200 ms. Every measured page meets them.

Low bandwidth: every page renders without JavaScript except the classroom and the theme switch; nothing polls in a
hidden tab except the classroom (5 s, to keep the call alive); the room poll stops while hidden.

## 5. Concurrency invariants

`TutorInvariants::check()`: every journal balances; no tutor holds two one-to-one lessons at overlapping times; no group
lesson has more held seats than its capacity; no duplicate enrolment, order key or marketplace request key; no booking,
seat or package paid by two orders; one sale earning per line; one successful payment per order.

`ConcurrencyInvariantsTest` (forked processes, each with its own connection; SQLite file in WAL/IMMEDIATE and
PostgreSQL under `bin/test-pgsql.sh`): four learners asking for the same slot get one lesson; a triple tap with one
Idempotency-Key is one lesson; three learners racing for the last group seat take one; four simultaneous enrolments are
one; one checkout sent four times with one key is one order; four identical Paystack webhooks at once pay once (one
event, one journal, ledger healthy). All pass on both engines. The load runs (section 3.2) end with the invariants
holding every time.

## 6. Tests

| Suite | Branch point | After (trunk with security merged) |
|---|---|---|
| PHP, SQLite | 906 tests, 899 passed, 7 skipped | 956 tests, 949 passed, 7 skipped, 0 failed |
| PHP, PostgreSQL (`bin/test-pgsql.sh`) | | 956 tests, 949 passed, 7 skipped, 0 failed |

New: `tests/Feature/Perf/PollCostTest` (4), `RoomPollCostTest` (1), `ListQueryBudgetTest` (4: matching, people list,
bookings list, primed age resolver), `ConcurrencyInvariantsTest` (6). Changed tests: none.

## 7. Changes in other owners' files

| File | Change | Decision |
|---|---|---|
| `app/Services/Scheduling/SlotFinder.php` (marketplace) | `startTimestamps()`, preloaded sessions and relations | D-1602 |
| `app/Domain/Marketplace/Services/MatchingService.php`, `Contracts/BatchLanguageLookup.php` (new) | batch preloads | D-1602 |
| `app/Domain/Integration/Adapters/Marketplace/{LanguageProfileAdapter,AcademicScopeAdapter}.php`, `Adapters/Safety/LearnerProfileBirthDates.php` | batch lookups, memoised names | D-1602, D-1604 |
| `app/Domain/Marketplace/Http/Controllers/Web/MarketplacePageController.php`, `Services/{BookingPresenter,ReviewService}.php`, `app/Domain/Student/Services/LearnerAgenda.php` | eager loads | D-1603 |
| `app/Domain/Safety/Services/{AgeResolver,AccountStanding}.php`, `Contracts/BatchBirthDateSource.php` (new), `app/Domain/Ops/Http/Controllers/UsersController.php` | `prime()` | D-1604 |
| `app/Domain/Ops/Insights/Overview.php`, `app/Domain/Teach/Services/Insights.php` | bucketing | D-1605 |
| `app/Domain/Teach/{Http/Controllers/StudentsController,Http/Controllers/BookingsController,Services/StudentBook}.php`, `resources/views/teach/students.blade.php` | paging | D-1606 |
| `app/Domain/Community/{Services/Activity,Services/Indicators,Http/Controllers/RoomController}.php` | version-first 304, accepted-answer query | D-1607, D-1608 |
| `config/database.php`, `.env.example` | SQLite pragmas from env | D-1610 |
| `app/View/Components/GuestLayout.php`, `resources/views/layouts/guest.blade.php`, `auth/two-factor-challenge.blade.php` | Livewire only where used | D-1611 |
| layouts `frame`, `public-top`, `public-foot`, `errors/_ecosystem-layout`, `components/application-mark`, `welcome`, `student/learning/player` | small mark, lazy images, compact outline | D-1611 |
| `resources/views/layouts/partials/head.blade.php` | no font preloads | D-1613 |
| `public/.htaccess` | gzip and cache rules | D-1612 |
| shared | `bootstrap/providers.php` one line | |

## 8. Owner items and limits

- **O-P1** If the site's document root is not `<app>/public`, copy the block at the end of `public/.htaccess` into the
  docroot's `.htaccess` (the deploy deliberately does not sync that file). Without it, phones download CSS and HTML
  uncompressed (2 to 3 times the bytes) and revalidate the hashed build files.
- **O-P2** Consider `CACHE_STORE=file` (or Redis) for the limiter; measure on the host with `scripts/perf/load.mjs`
  against a staging copy before trusting section 3.4.
- Capacity on the host is an estimate. The built-in server stands in for PHP-FPM. No physical phone: Chrome emulation.
  Byte counts are HTTP payloads (TLS not counted). The PostgreSQL page table was measured before the security merge;
  the security merge added per-request safeguarding reads (storefront 30 to 54 statements, about 1 ms on SQLite), and
  the one N+1 it introduced on the people list was fixed (66 to 20 statements).
