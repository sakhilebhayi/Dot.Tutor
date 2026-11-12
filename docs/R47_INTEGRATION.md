# R-47 integration: one system from five wave-1 domains

Owner: integration agent (decisions D-1400 onward). Code: `app/Domain/Integration/**`, provider `App\Domain\Integration\TutorIntegrationServiceProvider` (registered LAST in `bootstrap/providers.php`), route file `routes/tutor/integration.php`, migration `2026_11_10_900000_add_academic_foreign_keys.php`, `phpunit.pgsql.xml`, `bin/test-pgsql.sh`. Tests: `tests/Feature/Acceptance/*` (brief section 25 journeys), `tests/Feature/Integration/*`.

Wave 1 built safety, money, learning, academic and marketplace in parallel. Each defined its own ports with honest null defaults bound by `bindIf`. This provider binds every port to its owner's real implementation through thin adapters (plain `bind`, which replaces the `bindIf` defaults because this provider registers after them), registers the sellable items as money purchasables, and wires the callbacks between domains. `TutorIntegrationServiceProvider::BINDINGS` is the single list; every acceptance journey asserts each entry resolves to its adapter (no null default left on the path).

## 1. Bindings (port -> owner, adapter)

| Port (consumer) | Owner implementation | Adapter | Notes |
|---|---|---|---|
| `Academic\Contracts\AgeBandResolver` | `Safety\Enums\AgeBand::fromBirthDate` | `SafetyAgeBandResolver` | null birth date stays null |
| `Academic\Contracts\AcademicAudit` | `Safety\Audit::record` | `SafetyAcademicAudit` | hash-chained audit log |
| `Safety\Contracts\BirthDateSource` | `Academic\Services\LearnerProfiles::birthDateFor` | `LearnerProfileBirthDates` | last day of the stored birth month; safety's own records outrank it |
| `Money\Contracts\BuyerAge` | safety `AgeResolver::knownBand` (+ guardian link = minor) | `SafetyBuyerAge` | null when unknown |
| `Money\Contracts\PurchaseApprovals` | `Safety\Contracts\SafeguardingGate` (`pay`, kind `purchase`) + `GuardianApprovals` + `Guardians` | `GuardianPurchaseApprovals` | denied gate = order refused; requirement `guardian_approval:purchase` = held and one approval asked per order; `canPayFor` = buyer or a linked guardian; a marketplace package the guardian approved as a purchase is not asked again |
| `Money\Contracts\MoneyAudit` | `Safety\Audit::record` | `SafetyMoneyAudit` | |
| `Learning\...\AcademicScopeLookup` | academic models + `AcademicScope::canonicalSubjectId` | `AcademicScopeLookupAdapter` | curriculum/level/subject/topic exist and are active; level in curriculum; topic in subject (and level) |
| `Learning\...\EntitlementLookup` | `Money\Contracts\EntitlementCheck` | `MoneyEntitlementLookup` | learning `bundle` = money `course_bundle` |
| `Learning\...\CheckoutGateway` | `Money\Services\Orders` (quote) + `Checkout` | `MoneyCheckoutGateway` (via `Support\OrderStarter`) | statuses `redirect`, `paid`, `awaiting_guardian`, `unavailable`; refusals are money's `CheckoutRefused` ({error, message}) |
| `Learning\...\ContentReportSink` | `Safety\Contracts\ReportSink` | `SafetyContentReportSink` | copyright, incorrect (misinformation), other; returns `safety-report:{id}` |
| `Learning\...\SafeguardingCheck` | `SafeguardingGate` | `SafetySafeguardingCheck` | `publish` (commercial = the owner's own priced item; a child's pre-moderated publish is refused because learning has no pre-moderation queue), `pay`, `assign_work` = `message` in a booking context |
| `Learning\...\AuditTrail` | `Safety\Audit::record` | `SafetyAuditTrail` | |
| `Learning\...\TutorStanding` | `Marketplace\Contracts\TutorDirectory::find` | `DirectoryTutorStanding` | "has taught" stays learning's read of confirmed/completed bookings |
| `Marketplace\...\AcademicScopePort` | academic levels/curricula + `AcademicScope::isOffered` | `AcademicScopeAdapter` | range in one curriculum, right order, subject offered somewhere in it |
| `Marketplace\...\LanguageProfilePort` | `Academic\Contracts\LanguageProfile::teachingLanguages` | `LanguageProfileAdapter` | teaching languages only, never the interface locale |
| `Marketplace\...\LearnerAgePort` | safety `AgeResolver` | `SafetyLearnerAge` | |
| `Marketplace\...\GuardianApprovalsPort` | `Safety\Contracts\GuardianApprovals` | `SafetyGuardianApprovals` | lesson and seat = `booking`, package = `purchase` |
| `Marketplace\...\SafeguardingPort` | `SafeguardingGate` | `SafetySafeguardingPort` | an offering or series is asked about as its tutor (screening applies); a priced offering published is selling |
| `Marketplace\...\CheckoutPort` | money `Checkout` | `MoneyCheckoutPort` (via `OrderStarter`) | `pending` + Paystack URL, `paid`, `awaiting_guardian`, `unconfigured`; refusals become `MarketplaceException` with money's code and status |
| `Marketplace\...\ReportSinkPort` | `Safety\Contracts\ReportSink` | `SafetyReportSinkPort` | people's review reports only (see section 4) |
| `Marketplace\...\AuditPort` | `Safety\Audit::record` | `SafetyAuditPort` | subject stored as `marketplace.<type>`, id in the context |

Unchanged owner bindings: academic `AcademicScope`, `LanguageProfile`; safety `SafeguardingGate`, `GuardianApprovals`, `ReportSink`, `AuditLog`; money `Checkout`, `EntitlementCheck`; marketplace `TutorDirectory`; learning `ProgressFeed`.

## 2. Money purchasables (registered in `boot()`)

| Money type | Item | Class | Price | Earning release |
|---|---|---|---|---|
| `booking` (replaces money's adapter) | `tutor_sessions` | `MarketplaceBookingPurchasable` (extends money's `BookingPurchasable`) | v2 (has an offering): the booking's `price_minor` snapshot, payable only while `awaiting_payment` within its hold; pre-R-47 lessons keep money's rule | on delivery (completed); no-show and dispute need review |
| `seat` (new type) | `tutor_session_participants` | `SeatPurchasable` | seat `price_minor`, while awaiting payment | on delivery (attended) |
| `package` | `tutor_package_purchases` | `PackagePurchasable` | package `price_minor`, while awaiting payment | delivered when every lesson is used; expired with lessons left = finance review |
| `course` | learning `Course` | `SellablePurchasable` | `price_minor` | after the refund window |
| `resource` | learning `LibraryResource` | `SellablePurchasable` | `price_minor` | after the refund window |
| `course_bundle` (new type) | learning `Bundle` | `SellablePurchasable` | `price_minor` | after the refund window |

`Purchasables::TYPES` gained `seat` and `course_bundle` (money's own `bundle` is a different thing: a tutor's mixed bundle). `offering` and `room` stay unregistered: an offering is a template priced per booking, and rooms belong to the community agent.

## 3. Callbacks

| Event | Listener | Effect |
|---|---|---|
| `GuardianApproval` row updated to approved/declined (safety dashboard decision) | `ApplyGuardianDecision` | lesson/seat/package waiting for a guardian -> `BookingFlow::applyGuardianDecision`; weekly series -> each lesson's own pending approval is decided with it (which fans out per lesson); money `Order` -> `Orders::recordGuardianDecision` |
| `Money\Events\OrderPaid` | `FulfilPaidOrder` | booking/seat/package -> `PaymentService::paid` (idempotent; a pre-R-47 lesson not awaiting payment is only marked paid); course -> enrol buyer; course bundle -> enrol in each published course. Anything that cannot be fulfilled raises a critical `paid_item_not_fulfilled` finance issue |
| `Order` updated to expired/cancelled/guardian_refused | `OrderClosedUnpaid` | `PaymentService::failed` on marketplace lines (the booking's own hold decides release) |
| `Money\Events\OrderRefunded` | `RecordRefund` | lesson/seat: `refunded` event on the booking timeline; course/bundle fully refunded: purchase-based enrolments set to `refunded` (access already revoked by money) |
| `TutorSessionEvent` created with `refund_due_minor` > 0 on a money-paid booking or seat (cancel, dispute resolution, payment after release) | `RaiseRefundDue` | a `refund_due` finance issue naming the order line and amount. Refunds stay a finance action (money design: every refund is manual and goes through Paystack) |
| Safety moderation `hide`/`restore` | `SessionRating`, `LibraryResource` now implement `Safety\Contracts\Moderatable` | review removed/published through `ReviewService::moderate` (audited); resource taken down/restored |

`Support\OrderStarter` reuses an open order holding the same item (after a guardian approves, or a second click) and otherwise derives the idempotency key from the caller's key plus the number of earlier orders for that item, so a retry after an expired order is a new order. When Paystack cannot take payments it creates no order and says so (D-1403).

## 4. What stays null, partial or not built, and why

- `Safety\Contracts\RoomDirectory`: null (no rooms until the community agent). Room conversations stay 404.
- Marketplace automatic review holds (contact details found by its own screen) are not forwarded to safety: a safety report must name a person. They stay in the marketplace held-review queue.
- Learning library upload asks `publish` without a subject (learning's call), so a minor selling a priced resource is not caught by the commercial rule at upload; course publish and bundles are. Learning owner item.
- A 100% coupon cannot be redeemed through the learning/marketplace hand-offs on an environment without Paystack, because no order is created there (D-1403).
- Course reports: learning only forwards library reports; courses have no report endpoint yet.
- Learning `TutorStanding::hasTaught` is not in `TutorDirectory`; learning's own query is kept.
- `TutorDirectory::approved(['level_id'])` (marketplace) matched only scope range ends, not levels inside a range: fixed by QA (D-1809, `TutorDirectoryLevelTest`).
- Tutor teaching languages had no endpoint: `GET|PUT /api/tutor/me/teaching-languages` (integration route) edits the academic language profile; verification remains the academic operator screen.

## 5. JSON errors (D-1404)

`bootstrap/app.php`: JSON whenever the request is under `api/*` or expects JSON. `Support\JsonErrors::render` answers framework exceptions (`validation_failed` + `errors`, `unauthenticated`, `forbidden`, `not_found`, `too_many_requests`, `session_expired`, `http_<status>`); `JsonErrors::finish` adds `error` to any JSON error body that lacks one (500 becomes `server_error`, message hidden unless debug). Exceptions with their own `render()` (money `CheckoutRefused`, safety `SafeguardingDenied`) and the marketplace `MarketplaceException` renderer keep their codes. Removed duplicates: safety's validation/HTTP renderers for `safety/*`, marketplace's validation/auth/authorisation/not-found/throttle/HTTP renderers for `api/tutor/*`, academic's hand-rolled `ValidatesJson` body (now throws). Learning's controller-level `{error, message}` answers are domain errors and stay. Changed behaviour: an unauthenticated JSON request outside `api/*` now gets 401 JSON instead of a redirect to the login page (two academic tests updated).

## 6. Academic foreign keys (D-1406)

Added with restrict-on-delete: `tutor_teaching_scopes.curriculum_id, level_min_id, level_max_id`; `learning_courses.curriculum_id, level_id`; `learning_resources.curriculum_id, level_id, topic_id`; `learning_question_banks.topic_id`. The migration first lists any stored id with no parent row and stops with their names (nothing rewritten); until this release every write path refused academic ids, so none are expected. `tutor_offerings.level_ids` is JSON and has no key (validated on write). Tested on SQLite (rollback, orphan refusal, re-migrate on populated rows) and PostgreSQL.

## 7. Tests

- Acceptance (`tests/Feature/Acceptance`): `StudentJourneyTest`, `TutorJourneyTest`, `CourseCreatorJourneyTest`, `MinorSafetyJourneyTest`, sharing `BuildsJourneys` (academic reference data, faked Paystack HTTP with signed webhooks, real bindings asserted). The only direct writes: the operator's off-platform screening record (no automated register exists).
- Integration (`tests/Feature/Integration`): `CallbacksTest` (refund due, order closed unpaid, package approved once, weekly series fan-out, moderation hide/restore), `AcademicForeignKeysTest`.
- Tests that pinned a wave-1 null default now bind that default explicitly when the null behaviour is what they test (marketplace `fakeMinors`, one matching test, two money guardian tests) or assert the integrated behaviour (academic age band and seam defaults, learning checkout messages).

## 8. PostgreSQL

`bin/test-pgsql.sh [phpunit args]` creates a throwaway database `tutor_integ_<random>`, runs `phpunit.pgsql.xml` against it and drops it afterwards, whatever the outcome; it refuses a name that exists and never touches another database. Needs a local PostgreSQL the current user can create databases on (`PGHOST`, `PGPORT`, `PGUSER`, `PGPASSWORD` honoured). Results are in section 9.

## 9. Results (2026-11-10)

| Run | Tests | Passed | Skipped | Failed |
|---|---|---|---|---|
| Baseline at branch point (SQLite) | 661 | 654 | 7 | 0 |
| After the design-system merge, before any fix | 666 | crashed at 128 MB (trunk too) | | |
| Integration complete, SQLite in memory | 676 | 669 | 7 | 0 |
| Integration complete, PostgreSQL (`bin/test-pgsql.sh`) | 676 | 669 | 7 | 0 |
| After merging trunk with the ai domain, SQLite | 715 | 708 | 7 | 0 |
| After merging trunk with the ai domain, PostgreSQL | 715 | 708 | 7 | 0 |

The ai domain (merged last) binds its own driver (`AiTutor`: Anthropic only when switched on with a key, else the null driver) and reads learning through its own `LearningRecords`; it defines no port for integration to bind.

First PostgreSQL run: 48 failures and 1 error, from five causes, all fixed: the legacy-mapping test left its rows for later tests (most of the failures), `like` search was case-sensitive, the marketplace idempotency table stored a full class name in a 40-character column, a test inserting a refused row aborted the PostgreSQL transaction, and the refund-due listener compared a non-uuid reference with a uuid column.

Bugs found by the journeys and fixed: the marketplace gate question named the offering, not its tutor (screening only caught by the backstop); money could not price or accept payment for a v2 booking; a checkout without Paystack left an order open that then blocked quotes. See `docs/R47_AUDIT.md`, "Integration".
