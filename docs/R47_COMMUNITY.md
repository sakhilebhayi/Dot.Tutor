# R-47 community: learning rooms, Q&A, study groups, tutor rooms

Owner: community agent (decisions D-700 to D-716). Code: `app/Domain/Community/**`, routes `routes/tutor/community.php`, config `config/community.php`, migration `2026_11_10_700000_create_community_tables.php`, views `resources/views/community/**`, seeder `database/seeders/DemoCommunitySeeder.php` (local only), tests `tests/Feature/Community/**` (31 tests). Brief sections 5, 11, 15 and the learning community journey of 25.

## 1. What is built, and what is not

| Area | State | Proof |
|---|---|---|
| Subject rooms from academic offerings, created lazily, stage hubs, profile rooms, browsing | Built | `RoomsAndEligibilityTest` (lazy creation, profile landing) |
| Separation of learners under 18 and adults (one room per offering and audience) | Built | `test_learners_under_18_and_adults_are_in_separate_rooms_for_the_same_offering` |
| Age gates server side through safety's `SafeguardingGate` (`join_room` with the room as `AgeGatedSpace`) | Built | `test_an_unscreened_adult_cannot_see_or_post_in_a_room_for_minors`, `test_a_minor_without_guardian_consent_cannot_join_or_post`, `test_screened_tutors_join_as_tutors_and_operators_moderate_rooms_for_minors` |
| Safety's `RoomDirectory` seam bound to community rooms (room conversations now work) | Built | `test_safetys_room_directory_seam_finds_community_rooms` |
| Questions, answers, accepted answer by asker or tutor, resolved and reopen | Built | `test_the_asker_accepts_an_answer_and_the_question_is_resolved`, `test_a_tutor_in_the_room_can_accept_an_answer` |
| Discussions, explanations, revision techniques, replies | Built | `CommunityScreensTest` |
| Shared study material as links to published library items (learning owns files and licences) | Built | `test_shared_material_must_be_a_published_library_item` |
| Request tutoring help (marketplace hand-off, prefilled) | Built | `CommunityScreensTest` |
| Tutor and course recommendations by relevance | Built | `test_tutor_recommendations_rank_by_fit_and_only_screened_tutors_reach_minor_rooms`, `test_courses_for_the_room_are_published_ones_for_its_subject_and_level` |
| Room language labels, filter by language, original always shown | Built | `test_room_language_labels_list_the_languages_used_and_filter_threads` |
| Machine translation of posts through the AI endpoint | Wired (type `community.post` registered, view policy); the button shows only when the AI service is switched on and keyed | `test_posts_are_translatable_through_the_ai_endpoint_with_the_room_rule_as_the_view_policy`. Not run against a live model (AI is off in this environment) |
| Study groups: size cap, open, approval, invite code | Built | `test_study_groups_have_a_size_cap_and_join_rules` |
| Sessions with `.ics` calendar files, attendance, revision lists, group tasks (who did what) | Built | `test_sessions_calendar_attendance_revision_list_and_tasks` |
| Tutor rooms and workshops, free with capacity and enrolment rules | Built | `test_a_free_tutor_room_respects_capacity_and_members_only_read_it` |
| Paid tutor rooms through money (`room` purchasable, Paystack checkout, entitlement opens the room) | Built, end to end with the fake Paystack | `test_a_paid_room_is_bought_through_money_and_opens_once_paid` |
| A minor's paid room needs a guardian's approval first, asked once (D-717) | Built | `test_a_minor_needs_a_guardians_approval_before_paying_for_a_room_and_the_guardian_is_asked_once` |
| Reporting through safety's `ReportSink`; child-safety report holds at once; three reporters hold | Built | `test_a_report_reaches_the_safety_queue_and_a_child_safety_report_holds_the_post_at_once`, `test_posts_are_held_after_three_different_people_report_them` |
| Pre-moderation of posts by learners under 13 | Built | `test_posts_from_children_are_held_until_a_moderator_releases_them` |
| Contact-detail redaction in rooms for minors (original kept encrypted for moderators) | Built | `test_contact_details_are_hidden_in_rooms_for_minors_and_kept_for_moderators_only` |
| Rate limits (service level), edit history | Built | `test_posting_is_rate_limited_in_the_service`, `test_edits_keep_the_earlier_text_in_the_history` |
| Meaningful indicators only (no likes, followers, leaderboards) | Built | asserted in the Q&A test and on the My rooms screen |
| Polling with ETag and 304 | Built | `test_room_activity_answers_304_while_nothing_changed` |
| Live discussion (real-time chat) | Not built. Polling shows "new activity" every 30 s; no websockets on the host. Room conversations exist through safety's messaging (rooms are now found by `RoomDirectory`) | n/a |
| Notifications of answers and replies | Not built (comms domain, wave 2) | n/a |

## 2. Rooms

- A **subject room** is one per curriculum offering (`curriculum_subject_levels` row: curriculum + level + subject) and **audience**: `under_18` (age bands child and teen) or `adult`. It is created the first time someone opens it (`GET /rooms/levels/{level}/subjects/{offering}`), never by listing a hub; `subject_key` (`offering:{id}:{audience}`) is unique, so two people opening it at once get the same row.
- A **stage hub** (`/rooms/levels/{level}`) lists every active offering at the level (from `AcademicScope::offeringsAtLevel`) with its room's open and resolved question counts when the room exists, plus tutor rooms at the level.
- **My rooms** (`/rooms`) lands a learner in the rooms of their learner profile (`AcademicScope::forLearner`): the subjects they chose at their level, or every subject at the level when they chose none. It also lists rooms they joined, their indicators, and the curricula and levels to browse.
- **Audience**: from safety's `AccountStanding` (a minor gets `under_18`; unknown age follows `safety.unknown_age_policy`). Minors cannot switch audience. Approved tutors and operators may look at the under-18 hub (`?audience=under_18`), but every room asks the gate again.

## 3. Who may enter (server side)

Every read, post, join and group action calls `SafeguardingGate::allows($user, 'join_room', $room)` with the room as `AgeGatedSpace` (`safetyAgeBands()`: under_18 = child + teen, adult = adult; `safetyIsPaid()`; `safetyMemberIds()`). The gate's rules apply: a minor outside the room's bands is refused (`age_band_not_allowed`); an adult in a room with minor bands needs a current screening (`adult_screening_missing`) unless an operator; a minor without guardian consent is limited (`guardian_consent_required`); a suspended account is refused; a minor's paid room needs a guardian's approval (`guardian_approval:paid_room`). Guardians are not let into their child's rooms (they would see other children's posts without a screening): D-707.

Tutor rooms are readable only by the owner, active members and operators; others who pass the gate see the listing (title, price, places, join rule) and the way in. Removed members stay out. Opening a room page one may not enter shows a page with the reason in plain words (HTTP 403); the API answers `{error: 'safeguarding_denied', message, reasons}`.

## 4. Posts

Kinds: question, discussion, explanation, revision technique, material (thread kinds); answer (under a question), reply (under the others). Each post stores `original_locale` (BCP 47). Rules, all in `Services\Posts`:

1. Room access as above; posting in a subject room joins it.
2. Rate limits: 5 a minute and 60 a day per person (config), 10 edits a minute.
3. Safety `publish` check with `audience: room`; a child's post is held with `held_reason = pre_moderation` until an operator releases it.
4. Contact details (safety's `ContactRedactor`) are hidden in rooms for minors and in any post by a minor; the original text is kept encrypted in `original_text` (hidden from JSON) and shown only on the operator's held-post view.
5. Material must be a published library item the author can see; the post links to it (no file is stored by community).
6. Edits keep the earlier title and body in `community_post_revisions` (append-only); "Edited" links to the history.
7. Accepting: the asker, or a tutor in the room (an approved tutor who joined a subject room, or the owner of a tutor room). Accepting resolves the question; the asker or a tutor can mark it resolved without an answer, or open it again.
8. Withdraw: the author marks it removed (the row stays because reports may point at it).

**Reports** go to safety's queue through `ReportSink` (the `room_post` alias is also registered for safety's own report endpoint). A child-safety report (inappropriate contact with a minor) holds the post at once and opens safety's critical incident; three different reporters hold it. Held posts are hidden from everyone but the author and operators. `Post` implements `Moderatable` (safety's queue can hide and restore) and `Reportable` (reported person, people involved, snapshot).

**Held posts** (`/rooms/moderation`, `can:community.moderate` = platform operators until the ops roles land): release or remove, each audited through `Safety\Audit`.

## 5. Study groups, sessions, tutor rooms

- Groups live inside a room (so the room's gate covers every member): name, topic, exam focus, schedule note, size cap 2 to 30, join rule open / approval / invite code. Seats are taken with a conditional `UPDATE ... WHERE members_count < size_cap`.
- Sessions: title, start in the organiser's timezone (stored UTC), minutes, where. Each has a UID and an RFC 5545 `.ics` download for the person's own calendar. The organiser records attendance after the start.
- Revision list: anyone in the group adds a topic; each member ticks what they revised; everyone sees who.
- Group tasks: title, details, due date, assignees from the group; each assignee marks their part done with a note (who did what).
- Shared resources: links to published library items.
- Tutor rooms (`/teach/rooms`, workspace shell): an approved tutor picks an offering, audience, title, description, teaching language, join rule, capacity and price. A room for learners under 18 needs the tutor's screening (the gate checks the tutor as an adult entering the room). Paid rooms are open or by invitation (no approval step before paying). Workshops are sessions of the room.
- **Paid rooms**: `RoomPurchasable` (type `room`) is registered with money's `Purchasables`; money prices it server side. Enrolment needs an `Idempotency-Key`; `Checkout::createOrder` + `startPayment`. A minor's guardian approves first and is asked once: money holds the order and asks for a purchase approval (integration binding), or, when money does not hold it, community asks safety for a `paid_room` approval; no payment starts before approval (D-717). The place becomes active when money's `EntitlementCheck` says the learner owns it (on money's `OrderPaid` event, and re-checked on every read). If a payment lands after the last seat went, the place is not given and an audit entry `community.room.paid_but_full` asks finance to refund (D-711).

## 6. Recommendations (relevance, not popularity)

- Tutors: marketplace `TutorDirectory::approved(subject, level)`; score = teaching scope covers this curriculum and level (4), level open (1) or other (2), a teaching language the viewer listed (2), the room's language (1), a verification level on record (1); ties by id. Each card shows its reasons, met and unmet. In rooms for minors only tutors with a current safeguarding screening are listed (D-709). No ratings, review counts or booking counts enter the order.
- Courses: published learning courses for the room's subject at the level (or with no level), ranked by level match, curriculum match, language.
- Material: published library items for the subject at the level (or no level), this curriculum first.
- Request tutoring help: `marketplace.find` with `subject_id`, `level_id`, `curriculum_id` (and the room's language) prefilled.

## 7. Indicators

Per person, counted from records: questions resolved, answers accepted, resources contributed (material posts and group resources), study sessions attended, active groups, and milestones from those (first and tenth accepted answer, and so on). Per room: questions waiting and resolved (a work queue, not a score). There are no likes, follower counts, views or leaderboards anywhere, and nobody is ranked against anyone else.

## 8. Polling

`GET /api/tutor/community/{room}/activity` (and `/rooms/{room}/activity`) returns `{version, open_questions, resolved_questions, latest_post_id, last_activity_at}` with `ETag`; `If-None-Match` with the current version answers 304. The room page asks every 30 s while the tab is visible (inline script with the CSP nonce) and shows a notice with a link; it never reloads by itself. Limiter `community-polls`: 30 a minute.

## 9. API and screens

JSON under `/api/tutor/community/` (names `community.api.*`), the same actions as the screens under `/rooms` (names `rooms.*`), session authenticated with CSRF. Errors are `{error, message}` (validation adds `errors`; safeguarding adds `reasons`). `php artisan route:list --path=api/tutor/community` lists 46 routes. Limiter `community-writes` (30 a minute) on every write; reports also pass safety's `safety-reports`.

Screens, in the learner shell with the ds kit: My rooms, level hub, subject room with tabs Questions / Discussions / Material / Tutors and courses / Study groups (or Workshops and groups), question page, ask form (one form for every thread kind), edit and edit history, study group page, tutor-room listing (join, pay), denial page. Workspace shell: Tutor rooms (`teach.rooms`). Operations shell: held room posts. The young step is set for learners in the child band. People appear by first name and last initial (D-708).

## 10. Contract notes for other owners

- **safety**: community now binds `RoomDirectory` (plain `singleton`, replacing safety's `bindIf` null), registers the report alias `room_post`. Room conversations in safety's messaging now find real rooms.
- **money**: purchasable type `room` registered by community; community listens to `OrderPaid`.
- **ai**: translatable type `community.post` (`title`, `body`) registered; `PostPolicy::view` is the access rule the translation endpoint uses.
- **academic**: reads `AcademicScope::forLearner` and `offeringsAtLevel`, `LanguageProfile::learnerPreferences` and `interfaceLocale`.
- **marketplace**: reads `TutorDirectory::approved` and the `marketplace.find` route; reads `TutorProfile::isApproved()` to know who is a tutor.
- **learning**: reads published `LibraryResource` and `Course` rows and `LibraryService::canSee`; links to `learning.library.*` and `learning.courses.show`.
- **tutor UI (D-1200)**: community registers the route name `teach.rooms` (the workspace nav already points at it). If the tutor UI agent builds its own page under that name, keep one and link the other.
- **comms (D-1000)**: no notifications are sent for answers, acceptances, approvals or group sessions yet; events to hook: post created (answer), question resolved, group join request, session scheduled.

## 11. Owner items and limits

- Moderation capacity: posts from learners under 13 wait for an operator; someone must work `/rooms/moderation` daily.
- The pattern-based redaction misses numbers written in words (as safety's messaging); reports cover that.
- Group sessions have no video: they are scheduled times. The classroom domain (wave 2) can attach live lessons.
- Machine translation works only when the AI service is switched on (owner item in docs/R47_AI.md).
- A paid place bought after the room filled needs a manual refund (audit entry); it should be rare because money refuses the order when the room is full.
