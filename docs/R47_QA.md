# R-47 QA: browser end-to-end acceptance (brief sections 25 and 26)

Owner: qa agent (wave 3), branch `feat/r47-qa`, decisions D-1800 to D-1812 and D-1830 to D-1839. The PHP acceptance layer (`tests/Feature/Acceptance`, integration agent) proves the journeys over HTTP; this layer proves them in a real Chrome, on the integrated app, with only the external providers stubbed. Every step below was asserted by a spec in the run recorded in section 7, and each "Evidence" cell is what the spec read from the database or the stubs beyond the screen.

## 1. How to run

```
npm ci                                   # once: playwright-core 1.64.1 (dev only; drives the installed Chrome, downloads nothing)
node tests/e2e/run.mjs                   # build, boot, every spec, tear down (about 20 minutes)
node tests/e2e/run.mjs 07                # one spec file (file-name filter)
E2E_GREP='refund' node tests/e2e/run.mjs 07   # tests whose name matches, inside the chosen files
node tests/e2e/run.mjs --skip-build      # reuse public/build (built without VITE_REVERB_*)
node tests/e2e/run.mjs --serve           # boot only; writes tests/e2e/out/serve-env.json for running specs by hand
node tests/e2e/run.mjs --keep            # keep the throwaway database and storage folder
node tests/e2e/run.mjs --headed          # watch Chrome
E2E_VERBOSE=1 node tests/e2e/run.mjs     # stream the app and queue output
node tests/e2e/report.mjs                # the last run's evidence as Markdown tables
```

Needs PHP 8.4+, Node 22+ (`node:sqlite`, `node:test`), Google Chrome and `openssl`. Output in `tests/e2e/out/` (git-ignored): `summary.txt`, `evidence-<spec>.json`, `fail-<step>.png` for a failed step, `left-<test>-<n>.png` for every page a failed test left open, `route-sweep-<role>-<width>.json` and `route-sweep-failures.json`, `laravel.log`, `app.log`, `queue.log`, and screenshots of each finished journey. The run refuses to start specs unless the web workers write into the suite's own storage folder, and fails if anything tried to reach the internet.

The specs run in file order on one database: the route sweep (09) and the guessed-URL test (07) use the people and rows the journeys made. Run alone, a spec makes what it needs.

## 2. The world

| Piece | What it is |
|---|---|
| App | PHP's built-in server with 6 workers from `public/` (as `artisan serve`), `APP_ENV=local`, debug off, assets built as the deploy pipeline builds them, a throwaway SQLite file (WAL, busy timeout, IMMEDIATE transactions, D-1807) and storage folder in the OS temp dir, deleted afterwards. Laravel Boost off (dev-only package, D-1806). CSP as configured on the host (enforced with a per-page nonce since the security merge) |
| Queue | a `queue:work` process (the host's cron runs the same jobs once a minute) |
| Scheduler | not running; a spec runs the command cron would run (`money:settle`, `money:reconcile`, `safety:escalate-incidents`) after moving timestamps back |
| Reference data | `migrate` then `academic:sync` (languages, CAPS, IEB, tertiary, Cambridge, subjects) |
| Paystack | https stub (self-signed certificate, the only one PHP trusts): initialize, verify, refund (+ fetch, + a signed `refund.processed` webhook), transfer recipient, transfer (+ verify); signed `charge.success` webhooks to `/api/tutor/money/webhooks/paystack`; a control endpoint re-delivers a webhook (Paystack's retry). The hosted checkout page is the same stub: Chrome resolves `checkout.paystack.com` to it (D-1802) |
| InfoDot | http stub on `localhost` (a different origin from the app's `127.0.0.1`): a launch page that POSTs a one-time token to `/auth/ecosystem`, and the redeem endpoint the app calls with its service secret (D-1804) |
| Anthropic API | `AI_ENABLED=true` with a test key; the proxy tunnels `api.anthropic.com:443` to a local TLS stub that answers the Messages API shape, filling a JSON schema when one is asked for. Its text is prefixed `[e2e stub ...]` (D-1803) |
| Mail | an SMTP server that captures every message (`MAIL_MAILER=smtp`) |
| Internet | PHP: `HTTP(S)_PROXY` points at a recorder that refuses and lists every attempt; the run fails if the list is not empty. Chrome: `--host-resolver-rules` resolve nothing but the app, InfoDot's stub and the checkout stub (D-1802) |
| WebRTC | Chrome's fake camera and microphone; no STUN or TURN server (`CLASSROOM_STUN_URLS=none`), so two browsers on one machine connect over host candidates |
| People | `tests/e2e/support/world.php` (refuses any database but the suite's). Section 3 lists every direct write |

## 3. What is set up directly, not through a screen

Each of these is proven through the screens by another journey; a spec that only needs it to exist makes it here (D-1805).

| Fixture | Used by | Proven in the browser by |
|---|---|---|
| People with a local password (the local login form; production signs in through InfoDot, which journeys 01, 02 and 06 use) | all | 01, 02, 06 (InfoDot) |
| Operators with a role, written as `php artisan ops:grant` writes them | 02, 04, 06, 07, 09 | `TeamManagementTest` (ops agent) |
| An onboarded learner (country, CAPS level, subject, teaching languages, birth month) | 02-09 | 01 (onboarding), 06 (a child) |
| An approved tutor with scope, teaching languages, open hours and offerings | 01, 03-09 | 02 (the whole tutor journey) |
| A guardian link through safety's own invite and accept | 07, 09 | 06 (emailed invitation, InfoDot deep link, consent) |
| A course published through learning's `CoursePublisher` | 04, 07 | 03 (course studio) |
| A confirmed, paid lesson starting in five minutes | 08 | 01 and 02 (book, accept, pay) |
| One AI-test lesson set to confirmed (the tutor may assign work only to their students) | 07 F9 | 01 and 02 |
| Moved timestamps: a lesson's start (so the classroom opens now, then so it can be completed), an earning's release and availability dates, an incident's deadline | 01, 02, 06 | the scheduled commands run as cron runs them |

## 4. Journeys (brief section 25)

Results are from the final run (section 7). "F" rows are failure scenarios.

### 4.1 Student (spec 01; learner on a 390 phone, tutor at 1440)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Register and authenticate through InfoDot | Pass | InfoDot launch page posted the token; redeem call ok (platform tutor); users row 2 provisioned, email verified; landed on /learn |
| 2 | Select country and academic curriculum | Pass | learner_profiles: country ZA, curriculum za-caps (from the level), onboarded_at set |
| 3 | Select academic level and subjects | Pass | academic_level_id = CAPS Grade 11; learner_profile_subjects = [Mathematics] |
| 4 | Set preferred languages | Pass | user_languages: en/interface, en/prefers_teaching, zu/prefers_teaching |
| 5 | Search for tutoring | Pass | "Grade 11 Maths in isiZulu under R300 an hour" -> /find-a-tutor?need=Grade%2011%20Maths%20in%20isiZulu%20under%20R300%20an%20hour&level_id=11&curriculum_id=1&subject_id=25&languages%5 |
| 6 | Receive relevant tutor recommendations | Pass | Thandeka Mokoena listed with reasons including isiZulu (teaching language), scope Grade 10-12 covers Grade 11 |
| 7 | Review tutor qualifications and pricing | Pass | storefront /t/{id}: price R250.00 per hour, qualifications section, "Not yet verified" label (no evidence reviewed), scope table, teaching languages |
| 8 | Book a tutoring session | Pass | tutor_sessions 1: requested by the learner at 2026-11-15T08:00:00+00:00, price_minor 25000; the tutor accepted on /teach/bookings -> awaiting_payment |
| 9 | Complete payment | Pass | orders 1 paid, total_minor 25000; signed charge.success webhook 200; session confirmed and paid, order_reference = order uuid; ledger balanced (money:reconcile) |
| 10 | Attend the lesson | Pass | classroom: both joined (classroom_attendance 2 rows), RTCPeerConnection "connected" in both Chromes (fake camera and microphone), tutor published a summary and ended the room (classrooms.ended_at); tutor marked it completed after the start -> tutor_sessions.status completed |
| 11 | Review the tutor | Pass | session_ratings: rating 5 (published) for lesson 1 |
| 12 | Track learning progress | Pass | tutor set "After our lesson mwdvwo9e" on topic Linear equations in /teach/materials and assigned it; learner answered in /learning/assessments/.../take: attempt 100% (submitted); /learn/progress lists the topic |

### 4.2 Tutor (spec 02; tutor at 1440, learner at 390, reviewer and finance at 1440)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Register | Pass | InfoDot sign-in provisions the account; /teach/profile opens "Become a tutor" before any profile exists |
| 2 | Create a tutor profile | Pass | tutor_profiles 2: draft, headline, introduction, approach, hourly_rate_minor 30000; photo uploaded and stored |
| 3 | Select subjects and academic levels | Pass | tutor_teaching_scopes: Mathematics, CAPS Grade 10 to Grade 12 (validated against the academic structure) |
| 4 | Specify languages | Pass | user_languages can_teach_in: en (fluent, self-declared), zu (native, self-declared) |
| 5 | Submit verification documents | Pass | tutor_qualifications 1: degree with PDF evidence (tutor_qualification_evidence 1, private disk), sent for review -> submitted; the evidence URL answers 404 to another user |
| 6 | Configure availability | Pass | 7 tutor_availability_windows (every day 06:00-22:00 SAST) added on /teach/calendar |
| 7 | Set pricing | Pass | tutor_offerings 2: one-to-one, 60 min, price_minor 30000; the form showed "you keep R255.00" (15% commission) from the server before saving |
| 5b | Verification reviewed | Pass | reviewer (verification_reviewer role) verified qualification -> verified; approved the profile -> approved; safety_audit_log rows: 2 |
| 8 | Receive a tutoring request | Pass | tutor_sessions 2 requested by the learner; /teach (Today) shows "1 request waiting" |
| 9 | Accept the booking | Pass | Accept on /teach/bookings -> awaiting_payment (payment hold) |
| 10 | Deliver the lesson | Pass | classroom: both joined (classroom_attendance 2 rows), RTCPeerConnection "connected" in both Chromes (fake camera and microphone), tutor published a summary and ended the room (classrooms.ended_at); tutor marked it completed after the start -> tutor_sessions.status completed |
| 11 | Track earnings | Pass | money_earnings 2: gross 30000, commission 4500, net 25500, pending after the lesson; after the 24 h hold (moved back) money:settle released it -> available; /teach/earnings shows R255.00 |
| 12 | Receive or track payout | Pass | bank details saved (money_payout_accounts, number not stored in clear); finance drafted the batch on /ops/payouts -> money_payouts 1 25500; marked paid on the batch page -> paid; /teach/payouts shows it paid |

### 4.3 Course creator (spec 03; creator at 1440, buyer at 390)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Create a course | Pass | learning_courses 1: draft, owner = the tutor, title "Functions and graphs, Grade 11 (1z17)" |
| 4 | Configure course language and academic level | Pass | original_locale zu (isiZulu), level CAPS Grade 11 (curriculum 1), subject Mathematics; objectives and a 240-minute workload saved |
| 2 | Add sections and lessons | Pass | learning_course_sections 1 "Functions"; learning_lessons: text (free preview) and document (uploaded PDF) |
| 3 | Upload learning material | Pass | learning_materials 1: PDF, permission enrolled_download, stored on the private disk |
| 5 | Set pricing | Pass | paid, price_minor 19900; the price tab showed the server's "you keep R169.15" (15% commission) before saving; refund policy stored |
| 6 | Publish | Pass | learning_courses status published, current_version_id 1 |
| 7 | Receive enrolments | Pass | learner bought it on /learning/courses/1: money_orders paid 19900 via signed webhook; learning_enrolments active (source purchase) |
| 8 | Track student progress | Pass | learning_lesson_progress: 1 lesson completed by the learner; the learners tab lists them at 1 of 2 lessons (50%) |
| 9 | Receive earnings | Pass | money_earnings 3: gross 19900, commission 2985, net 16915, pending (released after the 7-day refund window, release_after 2026-11-19 18:31:58); shown on /teach/earnings |

### 4.4 Learning community (spec 04; adult Grade 11 learner at 390)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Enter the appropriate academic room | Pass | /rooms lists "Grade 11, CAPS" from the learner profile; community_rooms 1: Grade 11 CAPS, audience adult |
| 2 | Select a subject | Pass | Mathematics opened /rooms/levels/{Grade 11}/subjects/{offering 305} -> /rooms/1 (Mathematics, Grade 11, CAPS) |
| 3 | Ask a question | Pass | community_posts 1: question, visible, in room 1 |
| 4 | Share study material | Pass | learning_resources 1 uploaded with licence CC BY (published at upload) -> published (community); community_posts 2 (material) shares it in the room |
| 5 | Receive useful answers | Pass | tutor answered (community_posts 3); the asker got an in-app notice (notifications e615fa74-c966-4f8a-a0df-e3426b1bed2c, community_answer); the asker accepted it -> accepted_answer_id 3, question resolved |
| 6 | Find relevant tutors and courses | Pass | room "Tutors and courses" tab lists the Grade 10-12 Mathematics tutor (scope fit) and "Grade 11 functions starter (1htb)" (level and curriculum fit) |
| 7 | Report inappropriate content | Pass | community_post_reports -> safety_reports 1 (cheating_request); the safeguarding officer hid it from /ops/safeguarding/reports/1: post hidden, report actioned; audit rows 4 |

### 4.5 Multilingual (spec 05; learner at 390)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Select a preferred interface language | Pass | /language: Afrikaans chosen (coverage shown, "Drafted with AI and not yet checked"); user_languages interface = af; <html lang="af">; teaching languages unchanged (xh) |
| 2 | Search for a tutor in a preferred teaching language | Pass | find form: Mathematics, Grade 11, teaching language isiXhosa -> /find-a-tutor?subject_id=25&level_id=11&languages%5B0%5D=xh&bilingual_ok=1&curriculum_id=1 |
| 3 | Discover compatible tutors | Pass | the isiXhosa-teaching tutor is listed and the English-only tutor is not, with the language fit explained; the interface language (af) played no part |
| 4 | Access a resource in its original language | Pass | learning_resources 2: language zu; the page labels the original language as isiZulu and offers the original file |
| 5 | Use supported translation functionality | Pass | "Original and translation" -> English: AI driver called the Anthropic Messages API (local stub, model claude-opus-5-5); translations 1 stored provenance=machine, labelled on screen as machine translation |
| 6 | Verify that the original content remains accessible | Pass | the original isiZulu description is unchanged in learning_resources and shown beside the translation; the original file downloads (200, application/pdf) |

### 4.6 Safety (spec 06; a 10-year-old and a parent at 390, officers at 1440)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Create an age-appropriate student account | Pass | InfoDot sign-in; onboarding Grade 5 with a birth month 10 years ago -> sent to the guardian step (limited until a guardian links) |
| 2 | Apply required guardian controls | Pass | invitation emailed (captured by the SMTP stub: "Lerato Mokoena asked you to be their guardian on Dot.Tutor"); the parent signed in through InfoDot straight onto the accept link, gave consent and the birth date -> safety_guardian_links 1 active; /guardian/children/22 states that every booking, purchase, paid room and live lesson needs approval for a child under 13 and offers no switch to relax it |
| 3 | Attempt restricted interactions | Pass | the child requested a lesson with a screened tutor: tutor_sessions 3 awaiting_guardian, safety_guardian_approvals 1 pending; the tutor sees the request only as a number |
| 4 | Verify that permissions are enforced server-side | Pass | direct message to an adult: 403 safeguarding_denied; booking an unscreened tutor: 403 safeguarding; paying before approval: no order paid (HTTP 200); opening an adults room: 403; joining it with a forged POST: no membership (HTTP 403) |
| 5 | Report an unsafe interaction | Pass | safety_reports 2: inappropriate_contact_minor about the tutor, involves_minor 1, priority critical, respond by 2026-11-12 19:32:21 |
| 6 | Confirm the moderation and escalation workflow | Pass | the officer escalated report 2 from /ops/safeguarding/reports to incident INC-20261112-OYCNV (critical, respond by 2026-11-12 19:32:21); left unacknowledged past the deadline, safety:escalate-incidents raised it to level 1 and notified operators (11 incident notices); audit rows 8 |

### 4.7 Failure scenarios (spec 07)

| # | Step | Result | Evidence |
|---|---|---|---|
| F1a | Payment declined | Pass | Paystack checkout declined: order 4 pending_payment, lesson still awaiting_payment, no earning, no ledger sale; the lesson page offers Pay again |
| F1b | Retry, then a duplicate webhook | Pass | second checkout paid (order 4); Paystack's retry of the same signed charge.success answered 200 and changed nothing: earnings 4->4, ledger entries 20->20, webhook events 4->4; money:reconcile ran clean |
| F2 | Guardian declines | Pass | teen (15) requested lesson 5 -> awaiting_guardian; the parent declined on /guardian with a reason -> lesson cancelled (guardian declined), never reached the tutor; the teen was notified (notifications 849c8048-2618-4ee7-8ef2-8ba3b18fbadc) |
| F3 | Tutor declines | Pass | lesson 6 -> cancelled with the tutor's reason stored and shown on the learner's lesson page |
| F4 | Booking race on the last group seat | Pass | group lesson 7 (capacity 2, one seat taken): two learners pressed "Take a seat" at the same moment in two browsers -> exactly one seat granted (2 held, seats_taken 2/2); the other was told it is full |
| F5 | Refund of a course revokes access | Pass | finance refunded order 5 from /ops/payments/orders (Paystack /refund on the stub, money_refunds 1 processed); enrolment -> refunded; the course player now refuses the lesson (HTTP 403) |
| F6 | Guessed private file URLs | Pass | tutor qualification evidence (own-evidence API): 404; tutor qualification evidence (operator API): 403; course lesson media, unsigned: 403; course material, not enrolled: 403; screening evidence: 403; direct path /storage/app/private/learning: 404; direct path /storage/learning/x.pdf: 404; direct path /storage/marketplace/evidence/2/5224d7ff-99b6-40d2-9843-fc8815402a1d.pdf: 404; direct path /marketplace/evidence/2/5224d7ff-99b6-40d2-9843-fc8815402a1d.pdf: 404; direct path /.env: 404; direct path /storage/logs/laravel.log: 404; a signed-out browser is redirected to sign in or refused for every one |
| F7 | An adult messaging a child | Pass | an adult learner: 403 safeguarding_denied; an approved tutor with no booking: 403 safeguarding_denied; no conversation includes the child |
| F8 | A minor joining an adult room | Pass | a 16-year-old with guardian consent is placed in the under_18 room 3; the adults room 1 answers 403, a forged join 403 and a forged post 403 leave no membership and no post |
| F9 | AI refusing an active assessment | Pass | learner pasted a question from an assessment assigned to them ("After our lesson mwdvyii4") into /ai: ai_interactions 2 refused (assessment_question, reached_model 0); the Messages API stub received no call; the page explains the integrity rule |

### 4.8 Live classroom, two browsers (spec 08)

| # | Step | Result | Evidence |
|---|---|---|---|
| C1 | Live lesson in two browsers | Pass | PASS pre-join: fake camera and microphone found, the level meter moves (peak 99); PASS peer connection "connected" in both browsers; PASS tutor receives the learner's video (98 -> 158 frames); PASS learner receives the tutor's video (95 -> 155 frames); PASS audio flows both ways (tutor in 23789 B, learner in 23713 B); PASS a nominated candidate pair succeeded; PASS mute reaches the other side; PASS chat reaches the learner; PASS whiteboard stroke and text reach the learner, ink on their canvas (1224 painted pixels); PASS shared notes reach the tutor; PASS no horizontal scroll at 390 px (0px); PASS call controls at least 44 px on the phone (0 small); PASS the learner is told the lesson ended and their peer connections are closed (0 open); PASS attendance on the record for both people ([{"role":"tutor"},{"role":"learner"}]); PASS room ended on the record (tutor_ended); PASS no script errors in either page |


### 4.9 Definition of done (brief section 26): what this suite shows

| # | Item | Shown here | Not shown here |
|---|---|---|---|
| 3 | Grade 1 to postgraduate structure | learners at Grade 5, 10, 11 and adult; levels from `academic:sync` in every spec | postgraduate journeys (data only) |
| 4, 14 | Rooms functional, organised, moderated | 04 (room by level and subject, question, material, answer, report hidden by an officer), F8 | study groups |
| 5, 13 | Discovery, matching, language-aware | 01 steps 5-7, 05 steps 2-3, 04 step 6 (after B3) | |
| 6, 7 | Tutor profiles, bookings, earnings | 02 (all twelve steps), F1, F3, F4 | packages, weekly series |
| 8, 9 | Sell courses; access purchased courses | 03, F5 (refund revokes access) | bundles, coupons |
| 10, 11 | Upload and discover resources; ownership protected | 04 step 4, 05 step 4, F6 (guessed URLs refused) | |
| 12 | Multilingual beyond labels | 05 (interface vs teaching language, original kept, machine translation labelled) | human translation review |
| 15 | Real payment and payout integrations | Paystack flows against a stub of its documented API with signed webhooks, refunds, a payout batch | a live Paystack account |
| 16 | Child safety enforced server-side | 06 steps 3-6, F2, F7, F8 | |
| 17 | AI through genuine integration | the Anthropic SDK path end to end against a stub (05 step 5); F9 integrity refusal before any call | a real model |
| 18 | InfoDot authentication | 01 step 1, 02 step 1, 06 (deep link to the guardian invitation) | InfoDot itself |
| 19 | Mobile and accessibility | every journey's learner at 390; route sweep at 390 and 1440 (overflow, one h1) | screen readers, real phones |
| 20 | Critical automated tests pass | section 7 | |

## 5. Route sweep

Spec 09, after the journeys, on their data. 263 GET routes (after framework and token routes), 246 swept as 13 roles at 390 and 1440 (26 sweeps), 17 skipped for want of a value (api/tutor/community/groups/{group}, api/tutor/community/sessions/{session}/calendar.ics, api/tutor/learning/bundles/{bundle}, api/tutor/learning/flashcards/{set}/due, certificates/{code}, learn/practice/cards/{set}, learning/bundles/{bundle}, learning/flashcards/{set}/due, lessons/{tutorSession}/classroom/files/{resource}, ops/support/{conversation}, r/{code}, resources/{lessonResource}/download, rooms/groups/{group}, rooms/sessions/{session}/calendar.ics, safety/attachments/{attachment}, safety/conversations/{conversation}, u/{handle}); 0 problems.

Checks on every load: HTTP status below 500; on HTML pages exactly one h1, no horizontal overflow (scroll width minus viewport above 1 px), no console error other than an expected 401/402/403/404/409/419/422/429, no CSP violation (`securitypolicyviolation` events), no request to a host other than the app. 4xx answers are expected: a learner opening an operator page, a child opening an adults room, another person's lesson. JSON and file routes are checked for status only.

| Role @ width | Loads | Status classes | Problems |
|---|---|---|---|
| academic_editor@1440 | 246 | {2xx:144,4xx:102} | 0 |
| academic_editor@390 | 246 | {2xx:144,4xx:102} | 0 |
| admin@1440 | 246 | {2xx:206,4xx:40} | 0 |
| admin@390 | 246 | {2xx:206,4xx:40} | 0 |
| adult_learner@1440 | 246 | {2xx:147,4xx:99} | 0 |
| adult_learner@390 | 246 | {2xx:147,4xx:99} | 0 |
| child@1440 | 246 | {2xx:123,4xx:123} | 0 |
| child@390 | 246 | {2xx:123,4xx:123} | 0 |
| content_moderator@1440 | 246 | {2xx:148,4xx:98} | 0 |
| content_moderator@390 | 246 | {2xx:148,4xx:98} | 0 |
| finance@1440 | 246 | {2xx:163,4xx:83} | 0 |
| finance@390 | 246 | {2xx:163,4xx:83} | 0 |
| guardian@1440 | 246 | {2xx:134,4xx:112} | 0 |
| guardian@390 | 246 | {2xx:134,4xx:112} | 0 |
| guest@1440 | 246 | {2xx:163,4xx:83} | 0 |
| guest@390 | 246 | {2xx:163,4xx:83} | 0 |
| safeguarding_officer@1440 | 246 | {2xx:163,4xx:83} | 0 |
| safeguarding_officer@390 | 246 | {2xx:163,4xx:83} | 0 |
| support_agent@1440 | 246 | {2xx:145,4xx:101} | 0 |
| support_agent@390 | 246 | {2xx:145,4xx:101} | 0 |
| teen@1440 | 246 | {2xx:123,4xx:123} | 0 |
| teen@390 | 246 | {2xx:123,4xx:123} | 0 |
| tutor@1440 | 246 | {2xx:152,4xx:94} | 0 |
| tutor@390 | 246 | {2xx:152,4xx:94} | 0 |
| verification_reviewer@1440 | 246 | {2xx:149,4xx:97} | 0 |
| verification_reviewer@390 | 246 | {2xx:149,4xx:97} | 0 |

First sweep of this branch (before the fixes in section 6): 519 problems from five causes: no h1 on `/login` (so on every guest redirect), on the legal pages, on `/user/profile`, `/teams/create` and the legacy `/tutors/{id}` and `/sessions/{id}`; `/teams/create` loading `ui-avatars.com` (third-party host, CSP violation, console error). Second and final sweeps: 0.

Skipped routes have no row to point at in this world (no study group, flashcard set, certificate, message or support conversation, referral code or public handle was made by the journeys); their controllers are covered by the owning domains' feature tests.


## 6. Bugs found and fixed

| # | Severity | Found by | Bug | Fix | Regression test |
|---|---|---|---|---|---|
| B1 | High | journey 01 (500 in the app log) | /teach/materials/assessments/{id} 500 once assigned (Assignment::learner missing) | 47db6a2 | regression in CourseStudioAndMaterialsTest |
| B2 | Medium | journey 01 (500 during pay) | SQLite "database is locked" under concurrent requests: config/database.php ignored DB_BUSY_TIMEOUT/JOURNAL/TRANSACTION env | bb4c803 | covered by the e2e suite (two browsers, webhook during redirect); config only |
| B3 | High | journey 04 step 6 | TutorDirectory level filter matched only scope range ends: rooms Tutors tab empty for Grade 11 with Grade 10-12 tutors (community step 6, DoD 5) | 5507ce0 | TutorDirectoryLevelTest |
| B4 | Medium | journey 05 (home page text) | learner home + library said AI assistant/machine translation not switched on even when configured (app()->bound on concrete AiGateway) | cfc8c7f | StudentScreensTest::test_home_says_the_study_assistant_is_on_only_when_ai_is_configured |
| B5 | Medium | journey 06 (screening review did nothing) | service refusals carried as validation errors with no matching field (e.g. screening cleared without evidence) redirected back silently on every shell | 49311f7 | RefusedActionMessageTest |
| B6 | Low (a11y) | route sweep | no h1 on /login (every guest redirect), legal pages, /user/profile, /teams/*, legacy /tutors/{id} and /sessions/{id} | b31a77f | SingleHeadingTest |
| B7 | Medium (privacy) | route sweep | Jetstream default avatar loaded from ui-avatars.com with the person's initials (third-party host, CSP violation) on /teams/create and in every serialised User | 1c04ac7 | ProfilePhotoDefaultTest (security S-30 made the same fix in parallel; after the merge their override is kept, the QA test stays) |
| B8 | Low (operations) | app log after the runs | Expected refusals logged as ERROR with a stack trace: every learner poll after the tutor ended a lesson (409), and marketplace 4xx refusals (group lesson full, not waiting for payment) | 013d7f4, 31e3adb | ClassroomRefusalLoggingTest (classroom and marketplace; a 5xx is still reported) |
| H1 | High (harness) | first draft | A Playwright route does not see a redirected navigation: the app's 302 to the checkout reached the real checkout.paystack.com (a Cloudflare page) | Chrome host-resolver rules (D-1802) | every payment step asserts the stub page; Chrome resolves no other name |

Known gaps the task named, closed here:

| Gap | Fix | Tests |
|---|---|---|
| After sign-in Fortify sent everyone to `/dashboard` | `App\Support\RoleHome` as Fortify's login and two-factor responses and the InfoDot fallback: operator `/ops`, tutor (any profile, a draft included) `/teach`, guardian with no learning profile `/guardian`, everyone else `/learn`; a page asked for first still wins (D-1801, 459d93a) | `RoleHomeAfterSignInTest`; journeys 01 (new learner lands on `/learn`) and 02 |
| Comms did not listen to classroom events | `ClassroomNotifications`: `LessonStartingSoon` (classroom link, collapses with the lesson's reminders), `ClassroomOpened` (the others hear the room is open), `LessonSummaryPublished` (learners and guardians); `ClassroomEnded` and `FollowUpAssigned` deliberately send nothing new because the lesson-completed notice and the assignment notice already cover them (D-1830 to D-1834; 15cf733, 778d922, 0481c58) | `ClassroomNotificationsTest` (5) |
| Comms did not listen to community events | Community raises `AnswerPosted`, `QuestionResolved`, `JoinRequested` after commit and `StudyGroupUpdated` for group sessions; `CommunityNotifications` tells the asker, the answerer, the organiser or room tutor (D-1835 to D-1839) | `CommunityNotificationsTest` (6); journey 04 step 5 saw the asker's `community_answer` notice |
| `docs/R47_OPERATIONS.md` must name `php artisan ops:grant <email>` | Already there (step 2, `--role=admin`), also in `deployment.md` and the preflight message; checked, unchanged | |

## 7. Results

Final run, after merging the trunk with the security merge (`7dcbf18`: CSP enforced with a per-page nonce, unknown age = ask):

```
$ node tests/e2e/run.mjs
PASS 01-student-journey.spec.mjs (12 s)
PASS 02-tutor-journey.spec.mjs (15 s)
PASS 03-course-creator-journey.spec.mjs (6 s)
PASS 04-community-journey.spec.mjs (8 s)
PASS 05-multilingual-journey.spec.mjs (5 s)
PASS 06-safety-journey.spec.mjs (11 s)
PASS 07-failure-scenarios.spec.mjs (41 s)
PASS 08-classroom-webrtc.spec.mjs (22 s)
PASS 09-route-sweep.spec.mjs (649 s)

All specs passed
```

- 9 spec files, 17 tests: 6 journeys (53 steps with evidence, the tutor journey adding the review in ops), 9 failure scenarios in 8 tests (10 evidence rows), the classroom check (16 checks), the route sweep (26 sweeps, 6396 page loads, 0 problems).
- Outbound internet attempts: none (PHP proxy recorder empty; Chrome resolves nothing outside the run). The run's app log holds no ERROR or CRITICAL line.
- PHP suite after the final trunk merge, `php artisan test --compact`: 965 tests, 958 passed, 7 skipped, 0 failed (906 / 899 / 7 when this branch started). 24 of the new tests are QA's: role home 5, comms 11, directory 1, AI flag 1, refusal toast 1, headings 2, avatar 1, refusal logging 2. `view:cache` passes.


## 8. Not covered, and limits

- **Real providers:** no real Paystack, InfoDot, Anthropic, SMTP relay, push service or TURN server was contacted; their stubs follow the documented shapes the PHP fakes use. Live keys, real card networks and real bank transfers (payouts are marked paid by finance; Paystack transfers are off by default) are not exercised.
- **Browsers and devices:** Chrome only, desktop and an emulated 390 px phone; no Safari, Firefox or real phones. Both WebRTC peers ran on one machine (host candidates); a relay over TURN is not tested.
- **Push notifications** (no VAPID keys in the run) and **SMS** (not configured) are not exercised; in-app notices and captured emails are.
- **Time:** the scheduler is not running; specs move timestamps and run the commands. Reminders (`comms:reminders`) and the 24-hour lesson hold are not waited out in real time.
- **Load:** one race is tested (the last group seat with two browsers); there is no load or soak test.
- **Accessibility** beyond one h1, overflow and the 44 px classroom controls is the a11y agent's (`docs/R47_A11Y.md` when it lands); screen readers were not used here.
- **Not in the brief's journeys and not driven here:** packages and weekly series end to end, coupons, course bundles, disputes and chargebacks, study groups' sessions and tasks, account closure and data export (security agent), the tutor teaching assistant and AI question drafts, Dot.Notify forwarding, the referral link and promotional page.
- **Seeded academic data only:** CAPS Grade 1 to 12 and tertiary paths are used; IEB and Cambridge appear only in the sweep's parameters and the directory test.
