# R-47 safety domain: child safety, guardians, messaging, moderation, integrity, audit

Owner: safety agent (decisions D-400 to D-420). Code: `app/Domain/Safety/**`, `routes/tutor/safety.php`, `config/safety.php`, migration `2026_11_10_400000_create_safety_tables.php`, tests `tests/Feature/Safety/**` (110 tests). Legal background: `docs/R47_RESEARCH.md`, section "Safeguarding and legal context". Nothing in this file is a legal compliance claim.

## 1. What is built, and what is not

| Area | State | Proof |
|---|---|---|
| Age bands, account standing (limited minor, suspension) | Built | `AgeAndStandingTest` |
| Guardian invitation, sign-in, consent, revocation, guardian dashboard data | Built | `GuardianLinkingTest`, `MessagingTest::test_the_web_views_render`, browser check (390 px, 1440 px) |
| Guardian approvals (booking, purchase, paid room, live lesson) with expiry | Built | `GuardianApprovalsTest` |
| `SafeguardingGate` with structured reasons, eight abilities | Built | `SafeguardingGateTest` |
| Server-side backstop on the existing booking model | Built | `GuardianApprovalsTest` (booking via the real endpoint) |
| Messaging (contexts, guardians copied, redaction, attachments, read state, rate limits, reporting) | Built, JSON API plus minimal views | `MessagingTest` |
| Reports, moderation queue, actions, child-safety fast path | Built | `ReportsAndModerationTest` |
| Incidents with escalation timer and timeline | Built | `IncidentsTest` |
| Operator-recorded tutor screening (four eyes, evidence) | Built | `ScreeningAndIntegrityTest` |
| Academic integrity cases and policy | Built (records and sanctions; detection is not) | `ScreeningAndIntegrityTest` |
| Append-only, hash-chained audit log | Built | `AuditLogTest` |
| Brief section 25 safety journey | Built end to end over HTTP | `SafetyJourneyTest` |
| Room conversations and room joins | Rules built; rooms arrive in wave 2 (community). The default `RoomDirectory` knows no rooms, so room conversations answer 404 | `MessagingTest::test_room_conversations_need_a_room` |
| Live classroom, recording | Rules only (approval and consent data). No classroom exists yet (wave 2) | `SafeguardingGateTest` |
| Dates of birth from the learner profile | Seam (`BirthDateSource`) with a null default; the academic domain supplies it at integration | `AgeAndStandingTest` |
| Email delivery | Real mail notifications (invitation, approval request). Production mail must be configured; with `MAIL_MAILER=log` nothing reaches the guardian. The invite JSON also returns the link so a learner can pass it on; it works only for the invited address | n/a |
| Automated register checks, age verification by document, plagiarism detection, image scanning for abuse material | Not built and not claimed. See section 14 | n/a |

## 2. Age bands and account standing

`AgeBand`: child (under 13), teen (13 to 17), adult (18 or older); thresholds in `config/safety.php`. South African law treats everyone under 18 as a child; the child/teen split is a product distinction for how much a guardian approves.

Order of trust for a date of birth (`AgeResolver`): an operator-verified date, then a guardian-confirmed date (both in `safety_age_records`), then the learner's self-declared date from the academic profile (`BirthDateSource` seam). Anyone with an active guardian link is treated as a minor. Unknown age follows `safety.unknown_age_policy`: `adult` today so existing flows keep working until onboarding collects a date of birth (D-402), `restrict` treats unknown as a child and is the setting to switch to once every learner has one.

`AccountStanding`: a minor is **limited** until a linked guardian gives processing consent (POPIA s35(1)(a), competent person). A limited minor can read, invite a guardian, report and use support; booking, paying, messaging outside support, joining rooms, meeting live, publishing and uploading are refused. A **suspension** (`safety_account_restrictions`) refuses every gate ability until it ends or is lifted.

## 3. The gate and its rules

`App\Domain\Safety\Contracts\SafeguardingGate::allows(?User $actor, string $ability, mixed $subject = null, array $context = []): Decision`. A `Decision` is allowed or not, carries reasons (`{code, message}`), and may carry requirements (an allowed action that must still wait, for example `guardian_approval:booking`). `Decision::authorize()` throws `SafeguardingDenied`, a 403 that renders `{error: 'safeguarding_denied', message, reasons}` for JSON and a flash error for forms. Each ability is also a Laravel ability: `Gate::authorize('safety.book', $tutorProfile)`.

| Rule | Ability | Reason code | Test |
|---|---|---|---|
| Suspended accounts are refused everything | all | `account_suspended` | `test_a_suspended_account_is_denied_every_ability` |
| A minor without guardian consent is limited | book, pay, message (not support), join_room, meet_live, publish, upload | `guardian_consent_required` | `test_a_limited_minor_cannot_book_pay_publish_or_upload` |
| Every adult in an exchange with a minor holds a current screening, unless an operator or the minor's guardian | book, message, meet_live, join_room | `tutor_screening_missing`, `adult_screening_missing` | `test_a_minor_cannot_book_an_unscreened_tutor`, `test_an_unscreened_tutor_cannot_message_a_child_even_inside_a_booking` |
| A minor's booking waits for a guardian (child always, teen by setting) | book | requirement `guardian_approval:booking` | `test_a_minor_booking_a_screened_tutor_needs_guardian_approval` |
| A minor's payment waits for a guardian | pay | requirement `guardian_approval:purchase` or `:paid_room` | `test_a_minor_payment_needs_approval_until_approved` |
| No direct (context-free) conversation between an adult and a minor, nor any direct conversation involving a child | message | `minor_needs_context` | `test_an_adult_cannot_open_a_direct_conversation_with_a_child` |
| Room age bands; adults enter rooms with minors only when screened | join_room | `age_band_not_allowed`, `adult_screening_missing` | `test_room_age_bands_are_enforced` |
| A live one-to-one lesson with a child (or any minor whose settings require it) needs an approved `live_lesson` request | meet_live | `guardian_approval_missing` | `test_a_live_one_to_one_lesson_with_a_child_needs_guardian_approval_and_recording_needs_consent` |
| A recorded lesson needs every minor's recording consent | meet_live with `record` | `recording_consent_missing` | same, and `test_recording_is_allowed_once_the_guardian_consents` |
| Minors do not sell; children do not publish publicly and are pre-moderated | publish | `minor_cannot_sell`, `child_public_publish`, requirement `moderation:pre` | `test_minors_cannot_sell_and_children_do_not_publish_publicly` |
| Contact details are hidden when a minor is involved (guardian and operator excepted) | view_contact | `contact_hidden_minor` | `test_contact_details_are_hidden_when_a_minor_is_involved` |
| Bookings on the existing model: refused bookings are never created, a minor's booking asks the guardian, and it cannot become confirmed before approval | model observer | `guardian_approval_missing` | `test_a_minor_booking_through_the_existing_booking_endpoint_waits_for_the_guardian` |

## 4. Guardians and consent

1. The learner posts `POST /safety/guardians/invitations {email, relationship}` (parent, legal guardian, caregiver). Five a day. A 48-character token is generated; only its sha256 is stored. The emailed link is a Laravel temporary signed URL (72 hours).
2. The guardian opens the link; the auth middleware sends them through the normal sign-in (InfoDot in production) and back. The page shows the consent text, asks for the learner's date of birth, and offers separate, optional consent to lesson recording.
3. `POST /safety/guardians/accept/{invitation}` checks the token, that the signed-in email equals the invited address (configurable), that the guardian is not a known minor, not suspended, not the learner, and that the date of birth makes the learner a minor. It records the date of birth (`guardian_declared`), the link, and a consent row with the version (`safety.guardians.consent_version`) and the sha256 of the exact text (`ConsentText`).
4. The guardian is added to every conversation the learner is in, now and later.
5. Revocation: the guardian (or an operator, with a reason) revokes; consents are stamped withdrawn (never deleted), the guardian leaves the conversations, and the learner is limited again. The learner cannot revoke their own guardian.

Guardian dashboard (`GET /safety/guardian`, JSON or page) per child: account standing, age band, approvals waiting with approve and decline, upcoming lessons (existing `tutor_sessions`), purchase requests (from approvals; `orders_connected: false` until the money domain is wired), a messages summary (conversations, unread, how many messages had contact details hidden, link to read them), reports filed by, about, or inside the child's conversations, recording consent, and a teen's approval settings.

Consent text is plain language written by the agent; no lawyer has reviewed it (section 14).

## 5. Guardian approvals

`GuardianApprovals` (seam, real implementation `DatabaseGuardianApprovals`): `requires($learner, $kind)`, `request($learner, $kind, $subject, $details)`, `status()`, `isApproved()`; plus `decide()`, `cancel()`, `expireDue()`. Kinds: booking, purchase, paid_room, live_lesson. Child: all required. Teen defaults: booking, purchase, paid_room required; live_lesson not (config); a guardian may change a teen's settings, and with several guardians the strictest wins. Requests notify every linked guardian (database and mail), expire after 72 hours (read as expired at once, swept hourly), and only a linked guardian can decide. The learner is told the outcome.

## 6. Messaging

Contexts: `booking` (a `tutor_sessions` row; only its learner and tutor), `room` (via the `RoomDirectory` seam; members only), `support` (a person and Dot.Tutor operators; each request its own thread; open to limited minors), `direct` (adults only). One open conversation per booking and set of members.

Every send passes the gate, the per-minute rate limit (service level, so no route can skip it) and length limits. When a minor is a member: guardians are participants (read, write, report), contact details are hidden (`ContactRedactor`: email addresses, phone numbers of 9 to 15 digits, `@handles`, handles after the names of chat apps, and links to WhatsApp, Telegram, Signal, Snapchat, Instagram, TikTok, Facebook and Messenger, Discord, X, LinkedIn, WeChat, Line, Kik, Zoom, Google Meet, Teams and Skype) with a note saying why. The unredacted text is kept encrypted (`original_body`, hidden from serialisation) and shown only on the operator report view. Pattern matching misses a number spelled out in words; reporting covers that.

Attachments: up to five per message, 5 MB each, type sniffed (PDF, PNG, JPEG, plain text), images re-encoded with GD to drop metadata, stored on the private `safety` disk (`storage/app/private/safety`), streamed with `nosniff`, `Content-Disposition: attachment` and `no-store` to people who can see the message. No virus scanner exists on the host.

Read state: `last_read_at` per participant; unread counts in the list. Moderated (hidden) messages show "A moderator removed this message" to participants.

Operators read a conversation only when it is a support thread or something in it was reported; each such read is audited.

## 7. Reports, moderation and incidents

`ReportSink::report($reporter, $model, ReportCategory, $details)` for any model; models may implement `Reportable` (reported person, involved people, snapshot) and `Moderatable` (hide, restore). The HTTP endpoint accepts the aliases in `safety.reports.reportable` (message, profile, tutor_profile, review, resource; course and room post are added by their domains), and checks the model's own policy so nobody reports what they cannot see. Duplicate open reports by the same person return the first; 20 an hour.

Categories: harassment, inappropriate contact with a minor, cheating request, copyright, misinformation, spam, other. Priority: inappropriate contact with a minor is critical (1 hour) and opens a critical incident at once; harassment or other involving a minor is high (4 hours); spam is low (72 hours); the rest normal (24 hours). A cheating request opens an academic integrity case.

Queue (`GET /safety/operator/queue`): open and escalated reports, critical first, then by deadline, overdue flagged. Actions: hide, restore, warn, suspend (days or until lifted), unsuspend, escalate (opens an incident), dismiss. Every action writes an append-only `safety_moderation_actions` row and an audit entry, and resolves the report.

Incidents: reference `INC-YYYYMMDD-XXXXX`, severity critical (60 min), high (240), medium (1440). `safety:escalate-incidents` (every five minutes) raises an unacknowledged incident one level each time its timer runs out (cap 3) and notifies every operator. Acknowledge (takes ownership, stops the timer), note, refer (records an outside referral, for example a SAPS case number; nothing is sent from the platform), close with an outcome (closes linked reports). The timeline (`safety_incident_events`) is append-only.

## 8. Tutor screening

An operator does the check outside the platform and records it: kind (Child Protection Register Part B via a Form 29 enquiry to DSD, National Register for Sex Offenders via Form 8, police clearance, identity document, professional registration, reference), date checked, expiry, reference, notes and evidence (PDF or image, private). A second operator must review it (four eyes, configurable), and it cannot be cleared without evidence. A tutor counts as cleared for work with minors only when every kind in `safety.screening.required_kinds` (default: both registers) has a current cleared record. Nothing is checked automatically: neither register offers a public lookup. Whether the platform may lawfully submit Form 29 for tutors who are not its employees is a lawyer question (research note).

## 9. Academic integrity policy

Dot.Tutor supports learning and does not help anyone cheat. The policy areas, each recordable as a case (`safety_integrity_cases`):

- **Plagiarism:** presenting someone else's work as your own in a course, resource or answer.
- **Examination misconduct and requests to complete assessed work:** a tutor must not sit an exam or test for a learner, write graded assignments for submission, or share live exam papers. Learners must not ask. Reports of such requests open a case automatically.
- **Impersonation:** using another person's account, or someone attending a lesson or assessment as someone else.
- **Fake reviews:** reviews by people who did not take the lesson or course, paid or swapped reviews, a tutor reviewing themselves.
- **Misleading qualifications:** claims of degrees, registrations or experience a tutor does not hold. Verification labels appear only after a real review (marketplace domain).
- **Copyright infringement, fraudulent course sales, misrepresented resources:** selling or sharing material the seller does not have the right to share, or that is not what its listing says.

Process: an operator opens a case (or a report opens one), investigates, and decides upheld or not upheld with a finding. Sanctions: none, warning, content removed, review removed, label removed, suspension; warning and suspension are applied to the account automatically. Every step is audited. No automated plagiarism or AI-text detection exists; none is claimed.

## 10. Audit log

`App\Domain\Safety\Audit::record(?User $actor, string $action, Model|string|null $subject, array $context)`, bound to `DatabaseAuditLog` through the `AuditLog` contract (other domains call the facade or the contract). Rows are append-only (the model refuses updates and deletes) and hash-chained: each hash covers the previous hash, action, actor, subject, context and time, so an edit or deletion made around the model breaks the chain. `safety:audit-verify` runs daily and logs a critical error on a break; the operator audit endpoint shows where. The chain does not stop someone with database access rewriting every row; backups are the second copy. Writers are serialised (PostgreSQL advisory lock; MySQL and SQLite serialise already). Context is metadata only: never message text, documents or secrets.

## 11. Contracts for other domains

| Seam | Use |
|---|---|
| `SafeguardingGate` | Ask before every talk, meet, pay, publish and upload; honour requirements |
| `GuardianApprovals` | `requires`, `request`, `isApproved` for bookings, purchases, paid rooms, live lessons |
| `ReportSink` | File reports for courses, room posts, resources, reviews |
| `AuditLog` / `Audit::record` | Moderation, verification, money and admin actions |
| `ContactPrivacy::profileText($viewer, $owner, $text)` | Hide contact details in profile text shown to or about minors |
| Implement `Reportable`, `Moderatable` | Richer reports; hide and restore from the queue |
| Implement `AgeGatedSpace`, bind `RoomDirectory` (community) | Room age bands, paid rooms, room conversations |
| Bind `BirthDateSource` (academic, at integration) | Learner date of birth |

The gate contract gained a fourth `array $context` parameter and an `upload` ability (D-406).

## 12. Routes

All under `/safety`, signed in. Learner and guardian: `GET /me`, `POST /guardians/invitations`, `GET|POST /guardians/accept/{invitation}`, `GET /guardian`, `POST /guardian/approvals/{approval}`, `POST /guardian/links/{link}/revoke|recording|approval-settings`, `GET|POST /conversations`, `GET /conversations/{id}`, `POST /conversations/{id}/messages`, `GET /attachments/{id}`, `POST /reports`, `POST /messages/{id}/report`. Operators (`operator` middleware and `safety.operate`): `/operator/queue`, `/operator/reports/{id}` (+ `/actions`), `/operator/users/{id}` (+ `/actions`, `/age`, `/screening`), `/operator/incidents`, `/operator/screening`, `/operator/integrity`, `/operator/guardian-links/{id}/revoke`, `/operator/audit`. JSON errors follow `{error, message}`.

## 13. Operations

Scheduler (cron-driven `schedule:run`): `safety:escalate-incidents` every five minutes, `safety:expire-approvals` hourly, `safety:audit-verify` daily 04:00 UTC. Incident alerts are database notifications to every operator; email, push and SMS alerts wait for the comms domain (wave 2), so until then someone must look at the dashboard. Rate limiters `safety-invitations`, `safety-reports`, `safety-messages` are defined in the provider.

Account deletion: rows that are safeguarding evidence (reports, incidents, screening, moderation, restrictions) restrict deletion of the people they name, so self-service deletion of such an account fails until an operator handles it. Messages and conversations keep their text with the sender set to null.

## 14. Owner and lawyer items (not built, not claimed)

- A South African lawyer to review the consent text, the recording consent, the integrity policy and the questions in the research note (Children's Act s126 coverage, Form 29 for non-employees, POPIA s35 route, FPB s24C, CPA/ECT cooling-off for packages, minors' purchases).
- Decide the required screening kinds, and who the two reviewing operators are (four eyes blocks a one-person team; switch with `SAFETY_SCREENING_FOUR_EYES=false`, recorded).
- Production mail must be configured for invitations and approval requests.
- Switch `SAFETY_UNKNOWN_AGE_POLICY=restrict` once onboarding collects a date of birth from every learner.
- A reporting route for suspected child sexual abuse material (FPB hotline, SAPS) in the operator runbook; the platform does not scan images.
- Age is self-declared or guardian-confirmed; there is no document-based age verification, and a child could invite a second address they control. Operators can record a verified date of birth.
