# R-47 learning domain: courses, library, assessments, progress

Owner: learning agent (decisions D-600 onward). Code: `app/Domain/Learning`, routes `routes/tutor/learning.php`, config `config/learning.php`, migrations `2026_11_10_0600*`, views `resources/views/learning`, tests `tests/Feature/Learning` (52 tests). Brief sections 8, 9, 13 and the course creator journey (25).

## What exists

### Courses (brief 8)
- Draft course: title, description, subject (existing `subjects`), curriculum and level ids through the academic seam, original language (BCP 47), objectives, requirements, workload in minutes, completion rule, price.
- Structure: sections and lessons. Lesson types: reading (Markdown, HTML stripped), uploaded video (MP4/WebM), external video link (https, allow-listed hosts only: YouTube, Vimeo), document, image, slides, practice activity, quiz (a course quiz from the assessments module).
- Downloadable materials with a per-file permission: enrolled learners download, enrolled learners view in the browser, or anyone signed in (a free sample).
- Publish checks (`CoursePublisher::problems`) list every reason in plain words: no title/description/subject/objective, no price choice, paid without a price or refund policy, empty course or empty section, lesson without content or missing file, disallowed video link, quiz that is not this course's quiz or has no questions, owner not an approved tutor, safeguarding refusal, and "nothing has changed" for a repeat publish.
- Versions: publishing freezes an immutable snapshot (`learning_course_versions`). Enrolments pin a version; edits and deletions in the draft never reach an enrolled learner until they choose "move to the new version", and completions carry over for lessons that still exist. Files of a published course are never deleted (a version may point at them).
- Pricing: free or paid (`price_minor` + currency), bundles of the creator's own published courses (same currency), coupons and promotions applied by the money seam (`CheckoutGateway::quote`). The refund policy and price show on the course page before purchase.

### Enrolment and protected delivery
- Free courses enrol directly. Paid courses enrol only with an entitlement (`EntitlementLookup`, course or a bundle containing it), re-checked on every request so a refund ends access at once. Null default: nobody owns paid content.
- Every lesson, file and video goes through `CourseAccess` and an authorising controller. Files live on the private `learning` disk (registered in the provider, root `storage/app/private/learning`); no public path exists.
- Media URLs are short-lived signed routes (10 minutes) bound to the viewer (`u` must equal the signed-in user), re-authorised on each request, and served by `BinaryFileResponse` with HTTP range support (206) for video seeking, `nosniff`, `private, no-store`.
- Tests prove: guessed lesson ids, ids from another course, unsigned, tampered, expired or another user's media URLs, revoked entitlements and draft content are all refused; storage paths never appear in any JSON.

### Progress and certificates
- Lesson completion (quiz lessons complete only through a submitted attempt), resume point with video position, course percent, a list of learners' progress for the creator (name, version, percent only).
- Certificates only when the course defines a completion rule (`all_lessons` or `all_lessons_and_quizzes`). Code `XXXX-XXXX-XXXX`; public page `/certificates/{code}` (throttled, `noindex`) shows initials unless the holder opts in to the full name, and states it is not an accredited qualification.
- Reviews: enrolled learners who have completed at least one lesson, one review each (updatable), not the owner, through the safeguarding `publish` check.

### Resource library (brief 9)
- Upload: type decided by bytes (PDF, OOXML without macros, OpenDocument, JPEG/PNG/GIF/WebP re-encoded through GD to drop metadata, MP4/WebM); legacy binary Office files refused; 20 MB cap (video 200 MB); SHA-256 kept.
- Metadata: country, curriculum, level, subject, topic, language, type (12 kinds), difficulty, institution. Search and every filter in `GET library`.
- Ownership and licence: CC BY, CC BY-SA, all rights reserved, public domain, official source (with https link). Someone else's work needs an open licence, public domain or official source, plus a source link, and cannot be sold. Selling only own work, never public domain or official material.
- Past exam papers: accepted only as "official source" with the publisher's link (DBE publishes NSC past papers), free, and held as `pending_review` until a reviewer confirms the source. Otherwise refused.
- Quality tiers: community, tutor-created (approved tutor at upload), reviewed, officially sourced. The last two exist only after a recorded `ResourceReview` with the reviewer's name; owners cannot review their own material. Reviewers are platform operators until the ops agent adds a reviewer role.
- Reports: copyright, incorrect, improvement, other (forwarded through `ContentReportSink`, always stored locally). A complete copyright notice (rights holder, work link, good-faith statement) hides the file at once; the owner can file a counter-notice; an operator upholds (stays down; the same file hash cannot be uploaded again) or dismisses (restored). Recommendations (one per person, not by the owner). Withdraw by the owner.

### Assessments and progress insight (brief 13)
- Question banks per topic (with optional strand, e.g. Algebra) owned by their author; types: single choice, multi-select (all-or-nothing), numeric with tolerance (comma or point decimals), short text with accepted answers (case, spacing and trailing full stop ignored). Questions are retired, never edited, so past attempts stay valid.
- Assessments: course quizzes, tutor assessments assigned to learners the tutor actually teaches (confirmed/completed session or active enrolment in their course), practice. Pass mark, time limit (server clock, 30 s grace, late answers kept but cannot pass), attempt limit, shuffled order, answers revealed after submit or never.
- The answer key is hidden from every payload until submission (tested). The creator's own trial attempts do not count as evidence.
- Flashcards: sets (private or shared), SM-2 spaced review (intervals 1, 6, then times ease; grade under 3 resets).
- Mastery per topic from real marked answers, recomputed on read: last 10 answers for recent accuracy, at least 3 before any level is claimed, trend from the earlier and later halves of the last 20 (15-point threshold). Insights are fixed-template sentences ("You have improved in Algebra; Quadratic equations still needs attention."), deterministic, labelled "Computed from your answers. Not AI-generated, and not an official exam result."

### ProgressFeed seam (owned here)
`App\Domain\Learning\Contracts\ProgressFeed`, bound to `DatabaseProgressFeed`: `recentEvents` (lesson completed, attempt submitted, certificate issued), `mastery`, `insights`. For the AI, student and guardian UIs.

## Seams consumed (learning-side ports, D-601)

| Port (in `Learning\Contracts\Seams`) | Maps to owner seam | Default bound with `bindIf` |
|---|---|---|
| `AcademicScopeLookup` | `Academic\Contracts\AcademicScope` | `NullAcademicScopeLookup`: subjects checked against `subjects`; any curriculum, level or topic id refused |
| `EntitlementLookup` | `Money\Contracts\EntitlementCheck` | `NullEntitlementLookup`: owns nothing (only free content reachable) |
| `CheckoutGateway` | `Money\Contracts\Checkout` (+ coupons) | `NullCheckoutGateway`: list price, coupons refused, checkout answers 503 "Payments are not switched on yet" |
| `ContentReportSink` | `Safety\Contracts\ReportSink` | `NullContentReportSink`: not forwarded (local queue still works) |
| `SafeguardingCheck` | `Safety\Contracts\SafeguardingGate` (publish, pay, assign_work) | `NullSafeguardingCheck`: allows; the domain's own rules still apply |
| `AuditTrail` | `Safety\Audit::record` | `LogAuditTrail`: application log |
| `TutorStanding` | `Marketplace\Contracts\TutorDirectory` | `DatabaseTutorStanding`: reads `tutor_profiles` and `tutor_sessions` (real) |

`Course`, `Bundle` and `LibraryResource` implement `Learning\Contracts\Sellable` (type, id, price, currency, seller, description) for the integration provider to adapt into `Money\Contracts\Purchasable`.

## API

JSON under `/api/tutor/learning/*`, the same actions as the web screens under `/learning/*` (session authentication, CSRF; bearer-token clients are not supported in this wave). Errors are `{error, message}` or Laravel's 422 validation shape. Checkout routes require an `Idempotency-Key`. `php artisan route:list --path=learning` lists them (66 API routes, 66 web twins, the signed media route and the public certificate check). Rate limiters: `learning-uploads`, `learning-attempts`, `learning-reports` (provider).

## Screens (wave-1 minimal)

Studio list and create, course builder (structure, uploads, materials, details and price, publish checklist), catalogue and course page, lesson player with outline and resume, library search/upload and detail (report, counter-notice, recommend, withdraw), quiz take and result, public certificate check. Checked in a real browser at 390 px and 1440 px with the `DemoLearningSeeder` data; fixes made from that check: repeat publish disabled and refused, buy/enrol panel first on mobile, larger answer choices.

## Not built, or deliberately left out
- Subscriptions (brief says "where appropriate"): left to the money domain's offering model.
- Real payment, coupons and refunds: money domain; learning only hands off and re-checks entitlements.
- Video transcoding, HLS and DRM: no ffmpeg or workers on the shared host. "View only" is not DRM, and the builder says so.
- External videos are links, not embeds (no iframe until CSP is enforced); they are not protected, and the builder says so.
- No virus scanner on the host.
- Translation of course content: academic `Translations` seam (wave 2).
- Reviewer role beyond platform operators: ops agent.
- Mock exams as a separate type: a tutor assessment with a time limit covers it for now.
- PHPStan is not in CI and the base app already fails level 5 (Jetstream actions); learning models rely on `casts()` methods that Larastan does not read, so it reports false positives there.
