# R-47 Dot.Tutor: personal information record

Owner: security agent (D-1500 to D-1526). This is an engineering record of what personal information the platform holds, why, for how long, and what a person can do about it. It is written against the Protection of Personal Information Act (POPIA) as a checklist, **not** as a statement of compliance: no legal review has been done, the information officer is not yet named, and nothing here is a legal claim. Owner items are marked **Owner**.

## 1. Who the data is about, and what is held

| Subject | Held (where) | Purpose | Notes |
|---|---|---|---|
| **Every account** | Name, email, verified flag, password hash (random for InfoDot accounts), 2FA secret (encrypted), sessions (IP and user agent, database driver), API tokens (`users`, `sessions`, `personal_access_tokens`) | Sign-in (InfoDot SSO), security | Email change drops verification (S-01). |
| **Learners** (adults and children) | Learner profile: country, curriculum, level, subjects, **birth month only** (no day), tertiary institution only at tertiary levels, no school name (`learner_profiles`); languages by role (`user_languages`); reading step (`student_preferences`) | Matching, rooms, age band | Minimisation by academic (D-2xx). |
| | Bookings, notes, location notes, events, ratings (`tutor_sessions`, `tutor_session_events`, `session_ratings`); lesson files (`lesson_resources`, private disk) | Lessons | Notes from minors and on group lessons are stored with contact details removed (S-14); photos re-encoded (S-23). |
| | Courses, enrolments, progress, attempts and answers, mastery, flashcards, certificates (`learning_*`) | Learning | Certificates verify publicly by code with initials only. |
| | Rooms, posts, groups, sessions, tasks (`community_*`) | Community | Shown by first name and initial; contact details redacted for minors. |
| | Messages and attachments (`safety_messages`, private disk, images re-encoded), reports filed | Safe contact, moderation | Redaction keeps the original encrypted for operators only. |
| | Orders, payments, receipts, credits, entitlements (`money_*`) | Payment, tax records | No card data: Paystack holds it. |
| | Classroom attendance, chat, board, notes, signalling (`classroom_*`) | Live lessons | Not recorded (no media recording exists). Signalling purged after a day (S-22). |
| | AI prompts and answers (`ai_interactions`) | Study assistant | Off until a key is set; purged by `ai:purge-interactions`. |
| **Children** (under 18; POPIA treats them as special) | All of the above, plus age records (`safety_age_records`), guardian links, consents and approvals (`safety_guardian_*`, `safety_consents`) | Guardian consent (s35), approvals | Limited until a guardian consents; unknown age is protected as a child (S-03). Names shown to others as first name and initial (S-19). |
| **Guardians** | Link, relationship, consents given, approvals decided, the learner's birth date they confirmed | Oversight | A third party's email sits in an invitation until it is accepted or purged (S-22). |
| **Tutors** | Profile (headline, bio, approach, photo), qualifications and **evidence documents** (private disk), teaching scope and languages, availability, offerings, storefront handle; screening checks and evidence (private disk); earnings, payouts, **bank account** (holder and number encrypted, blind index, last four shown); coupons; student notes (`teach_student_notes`); referral visits | Marketplace, verification, safeguarding, payouts | Bank changes need a recent sign-in, are notified and held 72 hours (S-13). |
| **Operators** | Role assignments (`ops_role_assignments`), audit entries as actor | Accountability | |
| **Everyone, as audit subject** | `safety_audit_log` (append-only hash chain; actor id survives account closure by design) | Accountability, safeguarding | Includes operator reads of a person (`ops.account.viewed`, `safety.account.viewed`, screening evidence views). |

## 2. Processors outside South Africa (cross-border, POPIA s72)

| Processor | What goes there | When | Status |
|---|---|---|---|
| **InfoDot** (Dot ecosystem) | Sign-in identity (name, email, teams) | Every sign-in | Same owner; host location to be recorded. **Owner** |
| **Paystack** | Payer email, amount, reference; card data entered on Paystack's page | Payments, refunds, transfers (off by default) | Paystack is Nigerian/Stripe-owned; its processing terms apply. **Owner**: record the agreement. |
| **Anthropic** (Claude API) | Learner prompts with contact details redacted, age band guardrails | Only when `AI_ENABLED` and a key are set | US processor. **Owner**: decide and record before switching on, especially for minors. |
| **Google STUN** (`stun.l.google.com`, the default ICE server unless `CLASSROOM_STUN_URLS` is set) | The participant's network address during connection set-up | Live lessons | Peer-to-peer media do not pass through Google; no TURN relay exists. **Owner**: record, or host STUN. |
| Web Push services (Google FCM, Mozilla, Apple) | An encrypted notification payload | Push switched on by the person | Payload encrypted end to end (RFC 8291). |
| Mail provider (host SMTP) | Recipient email and message text | Notifications | Host-local. |

## 3. What a person can do (POPIA s23, s24)

- **See and take their data**: `/account/privacy` → "Download my data" (`GET /account/data-export`): one JSON file with every row where they are the subject, author, buyer, seller, learner or guardian, by table. Secrets, storage paths and full bank numbers are left out. Needs a sign-in in the last 15 minutes; 5 an hour; audited. Safeguarding records about them (reports, restrictions, incidents) are not in the self-service file and are given on request after a safeguarding officer's review (D-1522, **Owner**: the request route and the response time).
- **Correct**: profile pages; a birth month only towards a younger band without a guardian or support (S-04).
- **Close the account**: `/account/privacy` → "Close my account" (typed CLOSE, recent sign-in). Deleted outright when nothing depends on the person; otherwise closed (S-21): sign-in becomes impossible, profile, languages, settings, notifications, push subscriptions, tokens and sessions are erased, active guardian links revoked, the name replaced by "Closed account" (kept only when a safeguarding record is about the person), the email replaced by an unusable address. Money records stay for tax law; safeguarding records stay with the people they protect.

## 4. Operator access

Operators hold roles (ops matrix); reading a person's account and opening screening evidence are written to the audit log; the audit chain is verified daily (`safety:audit-verify`).

## 5. Retention

| Data | Rule | Implemented by |
|---|---|---|
| Unused guardian invitations | 30 days after expiry | `tutor:privacy-purge` (daily 03:50 UTC) |
| Classroom signalling | 1 day | `tutor:privacy-purge` (and per poll) |
| Password reset tokens | 1 day | `tutor:privacy-purge` |
| Failed job payloads | 30 days | `tutor:privacy-purge` |
| AI interactions | 180 days; reported or flagged 730 days (`AI_LOG_RETENTION_DAYS`, `AI_EVIDENCE_RETENTION_DAYS`) | `ai:purge-interactions` |
| Notification delivery records | 120 days | `comms:prune` |
| Money records (orders, receipts, ledger, earnings, payouts) | 5 years after the tax year (SARS) | **Owner** decision; nothing deletes them today |
| Messages, reports, incidents, restrictions, screening | for as long as safeguarding needs | **Owner** decision with the safeguarding lead |
| Lessons, courses, posts, attempts | while the account exists | Closure erases profile data; content others rely on (posts, reviews) stays under the short name or "Closed account" |
| Sessions | session lifetime | Laravel session garbage collection |

## 6. Open owner items

Information officer and PAIA manual; a privacy notice that matches this record (the Jetstream `policy.md` is generic); processor agreements (Paystack, Anthropic, InfoDot hosting); money and safeguarding retention periods; the route for safeguarding-record access requests; guardian identity verification (R47_SECURITY section 2); a breach-notification procedure (s22).
