# R-47 marketplace (marketplace agent, decisions D-300 onward)

Brief sections 6, 7, 16, 18 and the student and tutor journeys of 25. Code in `app/Domain/Marketplace`, routes in `routes/tutor/marketplace.php`, provider `TutorMarketplaceServiceProvider`, config `config/marketplace.php`, tests in `tests/Feature/Marketplace` (64 tests). Matching is in `docs/R47_MATCHING.md`.

## What exists

| Area | What it does | Where |
|---|---|---|
| Profile v2 | headline, introduction (`bio`), teaching approach, experience, years, formats (online, in person with area, group sizes), hourly rate in minor units, public handle | `TutorProfileService`, `PATCH /api/tutor/me/profile` |
| Photo | private upload, decoded and re-encoded to JPEG (metadata and appended bytes dropped), max 800 px, streamed with `nosniff` only while the tutor is approved | `PrivateFiles`, `POST /api/tutor/me/profile/photo`, `GET /tutors/{id}/photo` |
| Teaching scope | subject + optional curriculum + level range (academic ids, validated through the academic seam); keeps the legacy `tutor_subject` list in step | `PUT /api/tutor/me/scopes` |
| Qualifications | records with kind, title, institution, year; private evidence (PDF by magic bytes, or re-encoded image; at most 5 files); status unverified, submitted, verified, rejected; verified records are locked | `QualificationService`, `/api/tutor/me/qualifications*` |
| Verification | operator verifies, rejects or revokes evidence; assigns a level only when a verified qualification of a supporting kind exists; every decision is an append-only `tutor_verification_decisions` row plus the audit seam; operators cannot review themselves | `VerificationService`, `/api/tutor/operator/*`, `/operator/verification` |
| Public labels | "Not yet verified" without a level; qualification labels say "Verified by Dot.Tutor" only after review; legacy free text shown as "Self-declared, not verified"; rejected records hidden; peer label never says qualified, teacher or professional | `TutorPresenter`, `VerificationLevel` |
| Offerings | one-to-one, group (capacity 2-30), consultation, homework help, exam prep, revision, package (n lessons), weekly lessons (n weeks); `price_minor` + currency, 15-240 min in 15-min steps, format and area, subjects (within scope), levels, cancellation notice and late refund %; archived, never deleted | `OfferingService`, `/api/tutor/me/offerings*` |
| Storefront | everything a learner needs to decide: bio, scope, languages, qualifications with labels, formats, prices, offerings with cancellation terms, honest review counts and distribution, availability link, share URL; courses and materials are `null` (other domains, not connected) | `GET /api/tutor/tutors/{id}`, `/t/{id}` |
| Discovery | approved tutors, filters (subject, format, level of verification, text), daily-seeded fair order | `GET /api/tutor/tutors` |
| Matching | see R47_MATCHING.md | `POST /api/tutor/match`, `/find-a-tutor` |
| Booking v2 | state machine, events, idempotency, groups, series, packages, guardian and payment seams, cancellation policy, disputes | `BookingFlow`, `BookingStateMachine`, `PaymentService` |
| Reviews | completed bookings only, one per booking (a group seat is a booking), held on contact details, three distinct reports hold, tutor reply, operator moderation, only published reviews count | `ReviewService` |

## Booking state machine

Stored in `tutor_sessions.status`. The brief's "requested" is stored as the legacy value `pending` (D-310) and reported as `requested` by the API.

```
awaiting_guardian -> requested | cancelled
requested         -> awaiting_payment | confirmed | cancelled | no_show
awaiting_payment  -> confirmed | cancelled
confirmed         -> in_progress | completed | cancelled | no_show
in_progress       -> completed | disputed
completed         -> disputed
no_show           -> disputed
disputed          -> completed | cancelled      (operator)
```

Every transition locks the row, checks the move from the state the row is actually in, and appends a `tutor_session_events` row (event, from, to, actor, reason, context). Events and verification decisions refuse update and delete in the model; sessions with events cannot be deleted (foreign key restrict).

- **Request.** Learner, offering, subject, start. Checks: tutor approved and offering active; not booking yourself; safeguarding seam (`book`); subject in the offering; open hours, blocked dates, tutor and learner clashes (one-to-one and group seats) under the learner-then-tutor row locks. A minor (per the age seam) is created as `awaiting_guardian` and the guardian seam is asked.
- **Accept.** Tutor only, profile approved. Paid booking: `awaiting_payment` with a hold until the earlier of 48 hours or the start. Free or package-covered: `confirmed`. The legacy "Confirm" button goes through the same path.
- **Decline.** Tutor, reason required, stored on the booking.
- **Pay.** Learner starts a checkout through `CheckoutPort`; with the null default the API answers 503 `payments_not_configured` and nothing changes. The money domain calls `PaymentService::paid($type, $id, $orderRef)` after verifying server-side (idempotent); a seat paid after its hold ran out is recorded as `payment_after_release` with the refund due.
- **Cancel.** Learner or tutor before the lesson. The offering's policy decides the refund due (tutor cancels: 100%; learner within notice: 100%; later: the late %); recorded on the event as `refund_due_percent` and `refund_due_minor` for the money domain to act on. Package credits come back when the refund is full or nothing was paid.
- **Start, complete, no-show, dispute.** Start from 15 minutes before to the end (tutor). Complete once started, counted once (second call returns `already_completed`). No-show from 15 minutes after start (tutor). Dispute within 7 days of the end; an operator resolves with an outcome and refund %.
- **Expiry.** `tutor:expire-marketplace-holds` every five minutes: unpaid seat holds (30 min), unpaid accepted bookings past their hold, guardian holds still open at the start, expired packages.

### Groups

The tutor schedules a group lesson from a group offering (`POST /api/tutor/me/offerings/{id}/group-sessions`); it holds the tutor's time at once (`confirmed`, `kind = group`, `capacity`). Learners take seats (`tutor_session_participants`: awaiting_guardian, awaiting_payment, confirmed, cancelled, attended, no_show). Capacity is guarded by a conditional `UPDATE ... SET seats_taken = seats_taken + 1 WHERE seats_taken < capacity`, so two requests that both read "one seat left" cannot both win, whatever their in-memory state (test `test_two_learners_racing_for_the_last_seat_cannot_both_get_it`). A true two-process race was not run: the suite is SQLite in memory.

### Series and packages

- Weekly lessons: one request books the same local wall-clock time each week (DST-safe) for up to the offering's weeks; weeks that clash are skipped and listed; the tutor can accept the whole series. Each occurrence is priced and paid as a lesson.
- Packages: buying creates a `tutor_package_purchases` row (awaiting_guardian, awaiting_payment, active, exhausted, expired, cancelled). Lessons booked with `package_purchase_id` use a credit under a row lock and are `covered_by_package`.

### Idempotency

Every create (booking, series, package, seat, group schedule) takes `Idempotency-Key`. The key row is written in the same transaction as the resource under a unique `(user, scope, key)` index: same key and body replays the original (200, `replayed: true`); same key, different body is 422 `idempotency_mismatch`; a failed request releases its key.

## Seams (all `bindIf` null defaults in the provider)

The marketplace depends on its own ports in `App\Domain\Marketplace\Contracts`, named after the architecture seams; the wave-2 integration provider binds adapters onto the owners' interfaces (D-302). This avoids two agents writing the same file in another domain's namespace.

| Port | Owner's interface | Null default |
|---|---|---|
| `AcademicScopePort` | `Academic\Contracts\AcademicScope` | unavailable: scopes are subject-only and say so |
| `LanguageProfilePort` | `Academic\Contracts\LanguageProfile` | unavailable: language fit "not recorded" |
| `LearnerAgePort` | `Safety\AgeBand` via learner profile | age unknown (null) |
| `GuardianApprovalsPort` | `Safety\Contracts\GuardianApprovals` | adults and unknown ages need nothing; known minors are held, never approved |
| `SafeguardingPort` | `Safety\Contracts\SafeguardingGate::allows($actor, $ability, $subject, $context)` | allows with reason `safeguarding_not_connected` |
| `CheckoutPort` | `Money\Contracts\Checkout` | `unconfigured`: "Payments are not configured on Dot.Tutor yet ... Nothing has been charged." |
| `ReportSinkPort` | `Safety\Contracts\ReportSink` | returns null; held reviews stay in the marketplace's own queue |
| `AuditPort` | `Safety\Audit::record` | no-op; marketplace keeps its own append-only tables |

Marketplace provides `Contracts\TutorDirectory` (approved tutors with scope, languages and verification) via `EloquentTutorDirectory`.

Callbacks other domains use: `BookingFlow::applyGuardianDecision($session|$seat|$package, bool $approved, ?User $guardian, ?string $reason)` (safety) and `PaymentService::paid|failed($type, $id, $orderRef)` with types `tutor_session`, `tutor_session_participant`, `tutor_package_purchase` (money).

## Schema (all additive)

Migrations `2026_11_10_300100` to `300700`: profile v2 columns and `hourly_rate_minor` (backfilled); `tutor_qualifications`, `tutor_qualification_evidence`, `tutor_verification_decisions`; `tutor_teaching_scopes`; `tutor_offerings`, `tutor_offering_subject`; `tutor_booking_series`, `tutor_package_purchases`; booking columns on `tutor_sessions` (`rate_minor` backfilled, offering, kind, capacity, seats_taken, payment and guardian state, reasons, timing) plus `tutor_session_participants`, `tutor_session_events`, `marketplace_idempotency_keys`; review columns on `session_ratings` (status, held reason, tutor reply, moderation), the unique key widened from `session_id` to `(session_id, rated_by)`, and `tutor_review_reports`. The SQLite 1-5 rating triggers are restored after the table rebuild, on the way up and down. Backfill and rollback were checked on a file database with existing rows.

Academic ids (`curriculum_id`, `level_*_id`, `level_ids`) have no foreign keys yet; the integration step adds them once both branches are merged.

## API

All under `/api/tutor`, signed in (session or Sanctum), CSRF as the web group. Errors are `{error, message}` (validation adds `errors`). Discovery: `GET tutors`, `POST match`, `GET tutors/{id}`, `.../availability`, `.../offerings`, `.../group-sessions`, `.../reviews`. Tutor: `me/profile`, `me/profile/photo`, `me/scopes`, `me/qualifications*`, `me/evidence/{id}`, `me/offerings*`, `me/offerings/{id}/group-sessions`. Booking: `offerings/{id}/bookings|series|packages`, `group-sessions/{id}/seats`, `bookings[/{id}]`, `bookings/{id}/accept|decline|cancel|start|complete|no-show|dispute|pay`, `series/{id}/accept`, `seats/{id}/cancel|pay`, `packages/{id}/pay`. Reviews: `bookings/{id}/reviews`, `reviews/{id}/reply|report`. Operator (`operator` middleware): `operator/qualifications`, `operator/evidence/{id}`, `operator/qualifications/{id}/verify|reject|revoke`, `operator/tutors/{id}/verification-level|verification-history`, `operator/reviews`, `operator/reviews/{id}/moderate`, `operator/bookings/{id}/resolve-dispute`. Token clients (no session) are not supported for POSTs yet because the routes sit in the web group with CSRF.

## Pages (minimal, wave 2 replaces them)

`/find-a-tutor`, `/t/{id}` (storefront with a request form for one-to-one style offerings), `/my/bookings` (accept, decline with reason, cancel), `/operator/verification` (evidence queue with verify and reject). Built inside the existing app layout; checked at 375 px and 1440 px (no horizontal overflow) and with the Impeccable detector (no findings).

## Not built, on purpose

- Natural-language parsing of the need (wave-2 AI agent).
- Real payment, refunds and payouts (money agent): the marketplace records what is due.
- Guardian approval screens and age data (safety and academic agents).
- Courses and study materials on the storefront (learning agent): returned as `null`.
- Tutor rooms, messaging, notifications on booking changes (wave 2).
- A virus scanner for evidence files: none on the shared host; the operator page says so.
