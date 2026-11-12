# R-47 comms: notifications, reminders, channels (brief sections 19, 15, 21)

Owner: comms agent (wave 2), branch `feat/r47-comms`, decisions D-1000 to D-1016. Code: `app/Domain/Comms/**` (provider `TutorCommsServiceProvider`), `config/comms.php`, `config/notify.php`, `routes/tutor/comms.php`, `resources/views/comms/**`, `public/comms/push.js`, `public/comms-sw.js`, migration `2026_11_10_100000_create_comms_tables`. Tests: `tests/Feature/Comms/*` (59), `tests/Unit/Comms/PayloadEncrypterTest`, `tests/js/comms.test.mjs`. Adapted (copied, not imported) from Dot.Ehail R-46 (`docs/R46_COMMS.md` there): push on openssl, the delivery log, honest skip reasons, quiet duplicate suppression.

## 1. Model

Every notification is written to the in-app inbox first (Laravel `notifications` table). Push, email, SMS and Dot.Notify are copies: one `comms_deliveries` row per (event key, person, channel) with the real outcome: `queued`, `sending`, `sent` (the channel accepted it), `failed`, or `skipped` with a reason (`preference_off`, `own_action`, `child_in_app_only`, `mail_transport_not_delivering`, `push_not_configured`, `no_push_subscription`, `sms_not_configured`, `superseded`, `expired`, `quiet_hours` while held, `no_company`). The same rows are the work queue (`comms:deliver`, every minute; retries 1, 5, 15, 60 minutes, five attempts). Content is never stored twice: the worker reads title, text and link from the in-app record. Writing a notification never throws into the action that caused it.

Send one yourself: `app(\App\Domain\Comms\NotificationRouter::class)->send(new CommsMessage($user, $type, $eventKey, $title, $body, $url, $collapseKey, data: [...], aboutMinor: ...))`, and add the type to `config('comms.types')`. Or keep calling `$user->notify(...)` and add the class to `comms.source_types`.

## 2. Event to notification matrix

| Event (source) | Who hears | Type (category) |
|---|---|---|
| Booking requested, or released by a guardian (`tutor_session_events`: requested, guardian_approved) | tutor; a minor learner's guardians on a request | `lesson_requested` (lessons) |
| Booking held for a guardian (held_for_guardian) | learner (the guardian's ask is safety's own notice, below) | `lesson_awaiting_guardian` (guardian) |
| Guardian approval requested / decided (safety notifications) | guardians / learner; safety's own email recorded, not duplicated | `guardian_approval_requested`, `guardian_approval_decided` (guardian, mandatory) |
| Tutor accepted, payment due (accepted to awaiting_payment) | learner and guardians | `lesson_accepted` (lessons) |
| Lesson confirmed (accepted to confirmed, or paid) | learner and guardians; tutor too when paid; email carries the `.ics` | `lesson_confirmed` (lessons) |
| Declined, with the tutor's reason | learner and guardians | `lesson_declined` |
| Cancelled (by learner, tutor, operator), with refund due | the other party; guardians | `lesson_cancelled` |
| Lapsed (expired) | learner and guardians; tutor unless it lapsed at the guardian | `lesson_expired` |
| No-show, dispute raised, dispute resolved | the other party / both; guardians | `lesson_changed` |
| Lesson completed | learner (review invitation), guardians; tutor when the learner marked it | `lesson_review_invitation`, `lesson_completed` |
| Group seat paid, cancelled, expired, paid after release | seat holder and guardians | `seat_confirmed`, `seat_cancelled`, `lesson_changed` |
| Moderation cancelled a request (existing notice) | learner and guardians | `lesson_cancelled` |
| Lesson reminder, 24 h and 1 h before (cron) | tutor, learner(s), guardians (24 h only) | `lesson_reminder` (reminders) |
| `OrderPaid` | buyer and guardians; seller of a digital item (buyer not named) | `payment_received` (payments, mandatory), `sale_made` (teaching) |
| Payment declined (`Payment` to failed) | buyer and guardians | `payment_failed` (payments) |
| `OrderRefunded` | buyer and guardians | `refund_processed` (payments) |
| Payout drafted / paid / failed (`Payout`) | tutor | `payout_drafted`, `payout_paid`, `payout_failed` (earnings) |
| Course enrolment (`Enrolment` created) | learner (inbox only) and guardians; creator (one replaceable notice per course, no names) | `course_enrolled`, `course_new_learner` |
| Assignment set or due date changed (`Assignment`); due in a day and not handed in (cron) | learner | `assignment_set`, `assignment_due` (learning) |
| Resource approved (`ResourceReview`), taken down or restored (`LibraryResource` status) | owner | `resource_approved`, `resource_taken_down`, `resource_restored` (library) |
| Tutor application decided (existing notice) | tutor | `tutor_application_decided` (tutor_account) |
| Qualification or level decision (`VerificationDecision`) | tutor | `tutor_verification_decided` |
| Report reviewed (`Report` to actioned/dismissed) | reporter (and guardians); never what happened to the other person | `report_updated` (safety) |
| Account warned or suspended; safeguarding incident (existing notices) | the person and a minor's guardians; operators (urgent) | `account_warned`, `incident_alert` (safety) |
| `StudyGroupUpdated` (community dispatches it from `StudyGroups::schedule` and `cancelSession` for group sessions) | members except the actor; collapses per group | `study_group_update` (community) |
| `LessonStartingSoon` (classroom:sweep, about 15 min before) | tutor and learner(s), classroom link; no guardian copy (as the 1 h reminder); shares the booking's collapse key with the reminders (D-1830) | `lesson_starting` (reminders) |
| `ClassroomOpened` | the others in the lesson: learners when the tutor walked in, the tutor (and other group learners) when a learner did; never the opener (D-1831) | `classroom_open` (lessons) |
| `ClassroomEnded` | nobody (deliberate: `lesson_completed` and the summary say it, D-1832) | none |
| `LessonSummaryPublished` | learners and a minor's guardians, linking to `classroom.after`; not the tutor (D-1833) | `lesson_summary` (lessons) |
| `FollowUpAssigned` | nobody from this event: the `Assignment` observer's `assignment_set` is the one notice (D-1834) | (`assignment_set`) |
| `Community\Events\AnswerPosted` (a visible answer, or a held one when released) | the question's author, unless they answered themselves; one unread notice per question (D-1835) | `community_answer` (community) |
| `Community\Events\QuestionResolved` (answer accepted, or marked resolved) | the accepted answer's author unless they acted; the asker when a tutor resolved it (D-1836) | `community_answer_accepted`, `community_question_resolved` (community) |
| `Community\Events\JoinRequested` (approval rule: study group or free tutor room) | the organiser or the room's tutor; one unread notice per group or room with the waiting count (D-1837) | `community_join_request` (community) |
| Product news (none is sent today) | adults only, off by default | `product_update` (updates, marketing) |

Tests: `BookingNotificationsTest`, `DomainSourcesTest`, `RemindersAndCalendarTest`, `ClassroomNotificationsTest`, `CommunityNotificationsTest`.

## 3. Channels

- **In-app:** always on. `/comms/inbox` (page and JSON: `items`, `unread`, `badge`, `next_cursor`), `/comms/inbox/summary`, mark one (and its subject siblings) or all read. Each item lists its copies' outcomes in words. Links are only paths on this site.
- **Email:** sent from the delivery worker, which runs in the queued job; plain copy, one action, why it came and where to change it (or that it cannot be switched off), the lesson `.ics` attached to confirmations and reminders. With `MAIL_MAILER=log` (today's host) every email row is `skipped / mail_transport_not_delivering` and the settings page says email is not delivered.
- **Web Push:** off until `COMMS_VAPID_PUBLIC_KEY` and `COMMS_VAPID_PRIVATE_KEY` are set (`php artisan comms:vapid-keys`). The settings page turns push on per device (permission, subscribe, test, turn off) and says exactly why it cannot when it cannot (unsupported browser, blocked permission, iPhone outside the home screen).
- **SMS:** adapter only (D-1006); not configured, no numbers collected.
- **Dot.Notify:** section 6.

## 4. Preferences, quiet hours, minors

- Per category and channel (email, push), with defaults in `config/comms.php`. Tutor categories show only to tutors; marketing never to minors.
- Mandatory: safety, payments, guardian approvals: always on, a stored "off" is ignored, the API answers 422 `locked`, the page shows "Always on" with the reason.
- Quiet hours (default 21:00 to 07:00, the person's zone) hold push and SMS until they end; urgent safety alerts are not held; a held copy whose subject has passed is skipped as expired.
- Minors (D-1005): guardian copies of lessons, purchases and safety; children in-app only; first names only to tutors; nothing to Dot.Notify.

## 5. Calendar

`/comms/lessons/{id}/calendar.ics` and email attachments (D-1010): the lesson's own zone with a generated VTIMEZONE (tested for Africa/Johannesburg and Europe/London across the March change), stable UID, SEQUENCE, cancellation status, a 30-minute alarm, no contact details.

## 6. Dot.Notify (brief section 21)

Notify's ingest contract exists on its `main` branch (`POST /api/ingest/events`, signed with `NOTIFY_INGEST_SECRET_TUTOR`, routed by InfoDot company). Comms mirrors six event types to adults when `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` are set (D-1009). Off by default. Owner items: add `tutor.*` entries to Notify's `config/notify_events.php` catalogue, set the secret on both sides, and do not add email rules for `tutor.*` in Notify while Dot.Tutor sends its own email (they would duplicate).

## 7. Seams and contract notes

- `Comms\Contracts\MinorRouting` (bindIf to `Support\SafetyMinorRouting`); `Comms\Sms\SmsSender` (bindIf to `NullSmsSender`). The integration provider may rebind both.
- Events comms listens to are listed in section 2; no other owner's file was edited. Marketplace's `GuardianApprovalsPort` must be bound to safety (integration) for a guardian to be asked about a marketplace booking; until then the legacy safety observer asks.
- `Comms\Events\StudyGroupUpdated` for community.
- `<x-comms::bell />` (top-bar link with the unread count) for the ds/UI agents to place in the shells' top bar; not added to the layouts here (ds-owned).
- `phpunit.xml` memory limit 512M (D-1014): every branch running the full suite needs it.

## 8. Operations and owner steps

Scheduler: `comms:deliver` every minute, `comms:reminders` every five minutes, `comms:prune` daily (deliveries older than `COMMS_RETENTION_DAYS`, 120; revoked subscriptions). The existing cron `schedule:run` and `queue:work --stop-when-empty` are enough.

1. Mail: a real `MAIL_MAILER` (SMTP via no-reply@infodot.co.za). Until then email rows say not delivered.
2. Push: `php artisan comms:vapid-keys`, put the three lines in the host `.env` (never commit).
3. Dot.Notify: section 6.
4. SMS: choose a provider if SMS is wanted for safety alerts (needs number collection with confirmation, not built).

## 9. Verification

- PHP: 669 tests before (662 pass, 7 skipped, trunk with ds). After merging the latest trunk (with ai): 767 tests, 758 pass, 7 skipped, 2 fail, both in `tests/Feature/Ai/AiDriverTest` and reproduced with the comms provider removed (not comms; reported to the coordinator). Comms adds 62 (`tests/Feature/Comms`: 59, `tests/Unit/Comms`: 3); no existing test was changed. JS: `node --test tests/js/*.test.mjs` (2). `config:cache`, `route:cache`, `view:cache` pass.
- `npm run build`, `php artisan view:cache` pass. `impeccable detect` on `resources/views/comms`: none.
- Browser (local server 8709, a local demo user, notifications written through the real router): inbox and settings at 1440 and 390 in the dark theme, no horizontal overflow; a choice saved from the form persisted and the toast confirmed.

## 10. Limits (honest)

- No real device received a push (no VAPID keys locally; the embedded browser was not subscribed). Push is covered by tests that decrypt the body with the subscriber's key.
- No email reached an inbox (host mail logs only); emails are verified with `Mail::fake()`.
- No digest emails, per-device category choices or rescheduling notices (marketplace has no reschedule action; a moved lesson gets fresh reminders).
- Guardian copies need an active guardian link; a minor without one gets their own notices only (they are limited from booking and paying anyway).
