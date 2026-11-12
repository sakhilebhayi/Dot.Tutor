# R-47 live classroom (classroom agent, decisions D-800 onward)

Brief sections 12 (live tutoring and virtual classrooms), 15 (minors in live lessons), 13 (follow-up assignments) and the
section 25 steps "attend the lesson" (student) and "deliver the lesson" (tutor). Code in `app/Domain/Classroom`, routes in
`routes/tutor/classroom.php`, provider `App\Domain\Classroom\TutorClassroomServiceProvider` (registered before the
integration provider), config `config/classroom.php`, migration `2026_11_10_800000_create_classroom_tables.php`, client in
`resources/js/classroom`, styles `resources/css/classroom.css`, icons `public/icons/classroom.svg`, tests
`tests/Feature/Classroom` (30), `tests/js/classroom-board.test.mjs` (3), two-browser check `tests/e2e/classroom`.

## 1. What exists

| Area | What it does | Where |
|---|---|---|
| Classroom per lesson | One row per `tutor_sessions` booking (one-to-one or group), created on first use, with a random room key | `Classroom`, `ClassroomService::for` |
| Access | Server-side on every page and API call (re-checked at most every 30 s while in the call) | `ClassroomAccess::admit` |
| Real-time | WebRTC peer-to-peer mesh between browsers (cap 6), signalling over short polls, STUN from config, TURN from config (unset) | `resources/js/classroom/{room,peer,media}.js`, `ClassroomService`, `IceConfig` |
| Pre-join check | Camera preview (only you see it), camera, microphone and speaker choice, a live microphone level meter, browser and device checks, an honest connection line | `room.blade.php`, `lobby.js` |
| In the call | Audio, video, mute, camera off (releases the device), screen sharing (one at a time), device switching without renegotiation, connection quality from `getStats()` (round trip, loss, jitter, direct or relay), reconnect and ICE restart, "could not connect" with the fallback | `room.js`, `peer.js` |
| Tutor's controls | Mute a person, remove a person (cannot come back to this lesson), end the lesson (the tutor may reopen until the room closes) | `ClassroomService::tutorAction`, `end` |
| Chat | Whole room only (no private messages inside a lesson), stored, reportable to the safeguarding queue, contact details hidden when a minor is booked in (original kept encrypted for officers), 20 a minute | `ClassroomChat`, `ClassroomMessage` (Reportable, Moderatable) |
| Whiteboard | Pen, eraser, line, rectangle, ellipse, text; named colours; pages (up to 20); clear a page; append-only ops log per page; a page saved as a PNG into the lesson files; a text alternative listing the words on the page | `Whiteboard`, `BoardOp`, `board.js` |
| Shared notes | One plain-text page per lesson, autosaved; a stale save is refused with the current text and the person's own text kept beside it (no silent overwrite) | `SharedNotes` |
| Files | Shared into the lesson's resources (`lesson_resources`, private `lesson-resources` disk); type from the bytes (PDF, PNG, JPEG, text); images re-encoded; 10 MB; streamed with `nosniff` to people in the lesson only, also after it | `LessonFiles` |
| Attendance | From real joins: a span per join, extended by every poll, closed on leave, removal, end, or after 90 s of silence (counted to the last sign of life) | `AttendanceSpan`, `AfterLesson::attendance` |
| After the lesson | Attendance table, the tutor's private notes, the summary (draft or published to learners and guardians), follow-up work, mark completed, files and shared notes | `after.blade.php`, `AfterLesson` |
| My live lessons | The next two weeks and the last week, with "Open the classroom" or "Check my camera" | `/classroom`, `index.blade.php` |
| Recording | **Not built.** See section 6 | |

## 2. Who may come in, and when

`ClassroomAccess::admit($user, $session, $classroom)` answers an `Admission` (allowed, role, code, message, opens/closes):

1. **Participant:** the tutor; the one-to-one learner; a learner with a confirmed (or attended) group seat; a linked guardian
   of a minor learner in the lesson (joins to watch, microphone off on entry). Anyone else: `not_participant` (403), and the
   pages 403 too.
2. **Booking state:** confirmed or in progress (the marketplace state machine decides that). Requested, awaiting the
   guardian or payment: `not_confirmed`. Completed, cancelled, no-show, disputed: `lesson_over`.
3. **Time:** from 10 minutes before the start (`CLASSROOM_OPEN_MINUTES_BEFORE`) to 30 minutes after the end
   (`CLASSROOM_GRACE_MINUTES_AFTER`): `too_early`, `closed`. Too early still shows the pre-join check; the Join button opens
   at the opening time and the server checks again.
4. **The room:** ended by the tutor: `ended` (the tutor may come back in); removed: `removed`.
5. **Safeguarding:** `SafeguardingGate::allows($user, 'meet_live', $subject)` with the booking (one-to-one) or the classroom
   (a group: `Classroom` implements `InvolvesPeople`, so the gate sees everyone booked in). This brings in every safety rule:
   suspension, a minor without guardian consent (`guardian_consent_required`), adults unscreened with minors
   (`tutor_screening_missing`, `adult_screening_missing`, so a mixed adult and child group needs the adult learners
   screened too), and the guardian's approval of a live lesson for a child (`guardian_approval_missing`).

Live-lesson approvals: the page lists each minor who needs one and its state (approved, waiting, declined, expired, not
asked). The learner asks for their own; the tutor may ask for all (`POST .../classroom/approval`). The request goes through
`GuardianApprovals::request($learner, 'live_lesson', $subject)`, so it lands on the guardian's dashboard and mail like any
approval. A group is approved as its classroom, a one-to-one lesson as its booking. If a guardian revokes consent during a
lesson, the minor is taken out at the next check (test `test_a_guardian_withdrawing_consent_takes_the_minor_out_on_the_next_check`).

## 3. Real-time design (adapted from Dot.Engage, copied, not imported)

Dot.Engage's R-39 meeting room (`feat/r39-foundation`, `resources/js/meeting/*`, `MeetingService`, `IceConfig`) was read
and adapted: `peer.js` (perfect negotiation with a polite side, ICE restart, full rebuild, give up after a minute with
Retry, bandwidth by peer count, stats sampling), `media.js` (devices, degrade to audio only or listening only, camera off
releases the device, screen capture), `audio.js` (level meter), `api.js` (CSRF, per-tab client id, plain errors). The room,
lobby, whiteboard, chat, notes and files are new. Engage's waiting room and lock are not used: admission is the access
rules above.

- **Signalling:** `POST .../api/signal` stores offer, answer or candidate rows (`classroom_signals`, max 24 KB each) for one
  person; `POST .../api/poll` returns everything addressed to me since my last id, plus the roster, chat, board ops, notes
  (when the version changed) and files (when they changed). Polls every 1 s while negotiating or after a change, every 2 s
  when calm, every 5 s in a hidden tab, backing off on errors. A second window of the same person takes over; the first is
  told (`displaced`).
- **Purge:** signals older than 120 s (`CLASSROOM_SIGNAL_TTL_SECONDS`) are deleted on every poll and by
  `classroom:sweep` (every minute), and all of a room's signals when the lesson ends. They contain network addresses and are
  useless once connected.
- **Mesh cap:** 6 people at once (`CLASSROOM_MAX_IN_ROOM`): each browser uploads its video to every other one, so a tutor
  with five learners sends five streams; the video bitrate drops as the room grows. Group offerings allow up to 30 seats; a
  larger group than the cap is refused at the door (`room_full`) and the page says the in-browser room holds 6 and offers the
  tutor's own link. A selective forwarding unit would be needed beyond that (owner item, section 7).
- **STUN (D-804):** Google's public STUN (`stun:stun.l.google.com:19302`) by default. The privacy trade-off: the STUN
  server receives each participant's public IP address and port (personal information under POPIA, including children's),
  never media, names or accounts; without any STUN only people on the same network can connect. The page states this.
  `CLASSROOM_STUN_URLS` takes the owner's own server (a coturn TURN server also answers STUN), and `none` turns STUN off.
- **TURN:** `CLASSROOM_TURN_URLS` with `CLASSROOM_TURN_SECRET` (coturn `use-auth-secret`, credentials minted per person,
  expiring) or a fixed username and credential. Unset by default: no TURN server exists. The pre-join check says "Direct
  only"; when a connection to someone fails after a minute of trying, the room says "Your browser could not connect to NAME.
  No relay server is set up yet..." and offers Try again and, when the tutor set one, the tutor's own meeting link.
- **The fallback link:** the tutor saves an https link (Google Meet, Teams, Zoom or similar; `url:https`, host must be a
  domain, not an IP) on the lesson's classroom. Learners see it only inside that lesson's classroom; it opens outside
  Dot.Tutor with `noopener noreferrer`. It is stored on the classroom row, not on `tutor_sessions` (D-806).
- **Permissions-Policy:** the app denies camera and microphone everywhere (`SecurityHeaders`). The classroom page alone sends
  `camera=(self), microphone=(self), display-capture=(self)`; `SecurityHeaders` keeps a header the route set (tested).

## 4. Around the lesson

- **Start:** when the tutor joins a confirmed lesson the classroom calls `BookingFlow::start` (the marketplace's own rule:
  from 15 minutes before the start); a refusal is logged and the room still opens.
- **Complete:** the tutor's "Mark the lesson completed" calls `BookingFlow::complete` (counted once; the marketplace refuses
  before the start time and for unapproved tutors, and the refusal is shown). The marketplace state machine is not changed.
- **Summary:** written by the tutor, saved as a draft (learners do not see it) or published (learners and guardians see it
  on the lesson record). Contact details are hidden when a minor is booked in. Seam for the AI agent:
  `Classroom\Contracts\SummaryDrafter` (default `NullSummaryDrafter`: no draft, and the page says so). A draft would be put
  in the box labelled "AI draft" for the tutor to check; it is never published without the tutor pressing Publish.
- **Follow-up work:** `Classroom\Contracts\FollowUpAssigner`, default `LearningFollowUpAssigner`, writes learning's
  `learning_assignments` with learning's rules (the tutor's own tutor assessments with questions, learners they teach,
  learning's `SafeguardingCheck` with `assign_work`). Learning has no assignment service, only a controller, so the rules are
  mirrored (D-810). A learner in this lesson counts as taught (learning's own "has taught" read misses group seats).
- **Reminders and notifications:** the classroom sends none. The comms domain already sends lesson reminders 24 h and 1 h
  before each confirmed lesson from the bookings themselves. The classroom emits events that comms does not listen to yet
  (an integration item: "the classroom is open", "your tutor published a summary", "new follow-up work") (D-812):
  `LessonStartingSoon` (once per lesson, from `classroom:sweep`, 15 minutes ahead, with the classroom URL),
  `ClassroomOpened` (first join), `ClassroomEnded` (`tutor_ended` or `closed`), `LessonSummaryPublished` (learner ids),
  `FollowUpAssigned`.
- **Audit:** joins, removals, the end, published summaries and follow-up work go to `Safety\Contracts\AuditLog`.

## 5. Screens

Learners and guardians see the classroom in the learner shell, the tutor in the tutor workspace (the shell's Lessons item
already lights up on `classroom.*`). Built with the design system's tokens and kit (`x-td.*`); the call itself is a "study
carrel" on the deep surface (dark in both themes), the whiteboard a sheet of paper on the desk. Route names for the student
and tutor UI agents to link from bookings: `classroom.show`, `classroom.after`, `classroom.index`.

Checked in Chrome at 390 and 1440 (light; the board also in dark with reduced motion): no horizontal scroll, controls at
least 44 px on the phone, every control labelled (`aria-label`, `aria-pressed` on toggles, ARIA radio groups for tools and
colours with arrow keys, ARIA tabs from the kit), keyboard shortcuts m (microphone) and v (camera) ignored while typing,
a polite live region announcing joins, leaves, messages and state changes, focus moved to the microphone button on joining
and to the notice when the lesson ends. Motion is state only and cut under reduced motion.

## 6. Recording: not built (D-805)

Lessons are not recorded, and every classroom page says "This lesson is not recorded." Why: the shared cPanel host has no
room for video (a one-hour lesson is hundreds of megabytes per participant), no transcoding, no object storage with
retention and deletion, and no long-running process to assemble streams; recording in the browser and uploading would put
minors' video on a disk with no retention policy, access review or encryption at rest. Safety already stores the guardian's
recording consent and the gate's `record` context, so the consent rules are ready for when storage exists. Building a
consent prompt without a recorder would be a decorative control. Owner item: decide storage (an object store with a
retention policy), a retention period, and a lawyer's review of recording minors.

## 7. Not built, limits, owner items

- No TURN server (owner item: a coturn server, which also replaces Google STUN). Until then some school, office and mobile
  networks cannot connect; the room says so and offers the tutor's link.
- No SFU: rooms above 6 people at once are refused.
- Recording (section 6). Interactive exercises inside the call and presentation tools beyond screen sharing and the
  whiteboard are not built; the whiteboard has no undo, no images on the board, and no real-time cursors.
- No virus scanner for shared files (none on the host; the upload hint says so).
- Not tested on Safari, Firefox or real phones (Chrome and Chrome's phone emulation only), nor across two networks.
- `.env.example` (a shared file) was not edited; the `CLASSROOM_*` variables are documented here and in `config/classroom.php`.
- The security agent enforcing the CSP must keep `connect-src 'self'` (polling) and allow `blob:` images (whiteboard
  export); media streams set by `srcObject` need no `media-src`.

## 8. Verification

- `php artisan test --compact`: before the final trunk merge 745 tests, 738 pass, 7 skipped (branch point 715 / 708 / 7; +30 classroom tests); after merging trunk with comms and community, 840 tests, 833 pass, 7 skipped, 0 fail.
- `node --test tests/js/classroom-board.test.mjs`: 3 pass.
- Two-browser Chrome check (`tests/e2e/classroom/run.mjs`, Google Chrome via playwright-core 1.49.1, fake camera and
  microphone, tutor at 1440 x 1000, learner at 390 x 844), 19 of 19 on 2026-11-10 against the local server on port 8707:
  pre-join meter peak 100 of 100; both peer connections "connected"; frames decoded rising both ways (e.g. 97 to 158 and 95
  to 154 in 3 s, 640 x 360 playing in the tile); audio bytes and energy rising both ways; nominated candidate pair (host
  candidates, round trip about 0 ms on one machine); mute shown on the other side; chat delivered; a whiteboard stroke and
  text delivered (4 084 painted pixels on the learner's canvas); shared notes delivered; no horizontal scroll and no control
  under 44 px at 390; the m shortcut; ending the lesson told the learner and closed its peer connections; attendance listed
  both people; no script errors (an expected 409 on the poll after the end excluded). A second script checked the too-early
  state (Join disabled, opening time shown), the board in dark mode with reduced motion (no overflow) and that no control
  is unlabelled. Not verified: relay, two networks, Safari, Firefox, real devices, screen sharing in a headless browser.
