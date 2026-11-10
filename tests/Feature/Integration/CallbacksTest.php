<?php

namespace Tests\Feature\Integration;

use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Marketplace\Models\TutorBookingSeries;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Safety\Enums\ScreeningKind;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Domain\Safety\Services\Guardians;
use App\Models\SessionRating;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/** The cross-domain callbacks the acceptance journeys do not walk through. */
class CallbacksTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    /** @return array{0: User, 1: User, 2: int} tutor, learner, paid confirmed booking id */
    private function paidBooking(): array
    {
        [$tutor, $profile, $offeringId] = $this->tutorReadyToTeach('Tutor');
        $learner = $this->person('Learner');
        $id = $this->requestLesson($learner, $offeringId, $profile)->json('data.id');
        $this->actingAs($tutor)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/pay")->assertOk();
        $this->paystackConfirmsLatestPayment();

        return [$tutor, $learner, $id];
    }

    public function test_a_refund_due_on_a_cancelled_paid_booking_becomes_a_finance_issue(): void
    {
        [$tutor, , $id] = $this->paidBooking();

        $this->actingAs($tutor)->postJson("/api/tutor/bookings/{$id}/cancel", ['reason' => 'I am ill.'])->assertOk();

        $issue = FinanceIssue::query()->where('kind', 'refund_due')->sole();
        $this->assertSame(25000, (int) $issue->amount_minor, 'The tutor cancelled: everything is due back');
        $this->assertSame('order_line', $issue->subject_type);
    }

    public function test_an_order_closed_unpaid_marks_the_booking_payment_failed(): void
    {
        [$tutor, $profile, $offeringId] = $this->tutorReadyToTeach('Tutor');
        $learner = $this->person('Learner');
        $id = $this->requestLesson($learner, $offeringId, $profile)->json('data.id');
        $this->actingAs($tutor)->postJson("/api/tutor/bookings/{$id}/accept")->assertOk();
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/pay")->assertOk();

        // The learner closed Paystack's page without paying, then cancels the order.
        $this->paystack->verifyOverrides[$this->paystack->lastReference()] = ['status' => 'abandoned'];
        $this->actingAs($learner)->postJson('/api/tutor/money/orders/'.Order::query()->sole()->uuid.'/cancel')->assertOk();

        $this->assertSame('failed', TutorSession::query()->findOrFail($id)->payment_status);
        $this->assertSame('awaiting_payment', TutorSession::query()->findOrFail($id)->status, 'The booking hold decides when it is released');
        // Paying again starts a new order rather than reopening the closed one.
        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/pay")->assertOk()->assertJsonPath('payment.status', 'pending');
        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_package_the_guardian_approved_is_not_approved_twice_at_payment(): void
    {
        Notification::fake();
        [$tutor, $profile] = $this->tutorReadyToTeach('Tutor');
        $this->screen($tutor);
        $package = $this->actingAs($tutor)->postJson('/api/tutor/me/offerings', [
            'type' => 'package', 'title' => 'Five lessons', 'price_minor' => 100000, 'duration_minutes' => 60, 'format' => 'online',
            'package_lessons' => 5, 'package_valid_days' => 90, 'subject_ids' => [$this->subject('mathematics')->id],
        ])->assertCreated()->json('data.id');
        [$teen, $guardian] = $this->minorWithGuardian(15);

        $purchaseId = $this->actingAs($teen)->withHeader('Idempotency-Key', 'pkg-1')->postJson("/api/tutor/offerings/{$package}/packages")
            ->assertCreated()->json('data.id');
        $approval = GuardianApproval::query()->where('kind', 'purchase')->sole();
        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertOk();
        $this->assertSame(TutorPackagePurchase::AWAITING_PAYMENT, TutorPackagePurchase::query()->findOrFail($purchaseId)->status);

        $this->actingAs($teen)->postJson("/api/tutor/packages/{$purchaseId}/pay")->assertOk()->assertJsonPath('payment.status', 'pending');
        $this->assertSame(1, GuardianApproval::query()->where('kind', 'purchase')->count(), 'No second approval for the same package');
        $this->paystackConfirmsLatestPayment();
        $this->assertSame(TutorPackagePurchase::ACTIVE, TutorPackagePurchase::query()->findOrFail($purchaseId)->status);
    }

    public function test_a_weekly_series_approval_releases_every_lesson_in_it(): void
    {
        Notification::fake();
        [$tutor, $profile] = $this->tutorReadyToTeach('Tutor');
        $this->screen($tutor);
        $weekly = $this->actingAs($tutor)->postJson('/api/tutor/me/offerings', [
            'type' => 'recurring_weekly', 'title' => 'Weekly maths', 'price_minor' => 20000, 'duration_minutes' => 60, 'format' => 'online',
            'recurring_weeks' => 3, 'subject_ids' => [$this->subject('mathematics')->id],
        ])->assertCreated()->json('data.id');
        [$child, $guardian] = $this->minorWithGuardian(11);

        $this->actingAs($child)->withHeader('Idempotency-Key', 'series-1')->postJson("/api/tutor/offerings/{$weekly}/series", [
            'subject_id' => $this->subject('mathematics')->id, 'starts_at' => $this->slotAt($profile, 2)->format('Y-m-d\TH:i:s\Z'), 'weeks' => 3,
        ])->assertCreated();
        $this->assertSame(3, TutorSession::query()->where('status', 'awaiting_guardian')->count());

        $seriesApproval = GuardianApproval::query()->where('subject_type', (new TutorBookingSeries)->getMorphClass())->sole();
        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $seriesApproval), ['decision' => 'approve'])->assertOk();

        $this->assertSame(3, TutorSession::query()->where('status', 'pending')->where('guardian_status', 'approved')->count());
        $this->assertSame(0, GuardianApproval::query()->where('status', 'pending')->count(), 'Each lesson\'s own approval is decided with the series');
    }

    public function test_moderation_actions_reach_the_reported_review_and_resource(): void
    {
        [$tutor, $learner, $id] = $this->paidBooking();
        $session = TutorSession::query()->findOrFail($id);
        $this->travelTo($session->starts_at->copy()->addMinutes(65));
        $this->actingAs($tutor)->postJson("/api/tutor/bookings/{$id}/start");
        $this->actingAs($tutor)->postJson("/api/tutor/bookings/{$id}/complete")->assertOk();
        $reviewId = $this->actingAs($learner)->postJson("/api/tutor/bookings/{$id}/reviews", ['rating' => 1, 'review' => 'Rubbish tutor, avoid.'])->json('data.id');

        // A person's report on the review goes to the safety queue; hiding it there removes the review.
        $this->actingAs($this->person('Reader'))->postJson("/api/tutor/reviews/{$reviewId}/report", ['reason' => 'Abusive, not about the lesson.'])->assertCreated();
        $report = Report::query()->sole();
        $this->actingAs($this->operator)->postJson(route('safety.operator.reports.act', $report), ['action' => 'hide', 'reason' => 'Abusive'])->assertCreated();
        $this->assertSame('removed', SessionRating::query()->findOrFail($reviewId)->status);

        // A library report is forwarded to the same queue; hide and restore reach the resource.
        $resourceId = $this->actingAs($tutor)->post('/api/tutor/learning/library', [
            'title' => 'Functions summary', 'description' => 'Grade 11 functions on one page.', 'type' => 'summary', 'country_code' => 'za',
            'subject_id' => $this->subject('mathematics')->id, 'topic' => 'Functions', 'language' => 'en', 'difficulty' => 'standard',
            'ownership' => 'own_work', 'licence' => 'cc_by', 'rights_confirmed' => '1',
            'file' => UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->actingAs($learner)->postJson("/api/tutor/learning/library/{$resourceId}/reports", ['kind' => 'incorrect', 'details' => 'The turning point formula is wrong.'])->assertCreated();
        $forwarded = Report::query()->where('category', 'misinformation')->sole();
        $this->actingAs($this->operator)->postJson(route('safety.operator.reports.act', $forwarded), ['action' => 'hide', 'reason' => 'Checking it'])->assertCreated();
        $this->assertSame('taken_down', LibraryResource::query()->findOrFail($resourceId)->status->value);
        $this->actingAs($this->operator)->postJson(route('safety.operator.reports.act', $forwarded), ['action' => 'restore', 'reason' => 'Formula is right'])->assertCreated();
        $this->assertSame('published', LibraryResource::query()->findOrFail($resourceId)->status->value);
    }

    /** @return array{0: User, 1: User} */
    private function minorWithGuardian(int $age): array
    {
        $minor = $this->person('Minor');
        $this->learnerProfile($minor, $age, 'za-caps', $age >= 14 ? 'grade-10' : 'grade-6');
        $guardian = $this->person('Guardian');
        $invite = app(Guardians::class)->invite($minor, $guardian->email, 'parent');
        parse_str((string) parse_url($invite['url'], PHP_URL_QUERY), $query);
        app(Guardians::class)->accept($invite['invitation'], $query['token'], $guardian, CarbonImmutable::now()->subYears($age)->subMonths(2));

        return [$minor, $guardian];
    }

    private function screen(User $tutor): void
    {
        $reviewer = $this->makeOperator();
        foreach (config('safety.screening.required_kinds') as $kind) {
            ScreeningCheck::create([
                'user_id' => $tutor->id, 'kind' => ScreeningKind::from($kind), 'status' => ScreeningCheck::CLEARED,
                'checked_on' => now()->subDay()->toDateString(), 'evidence_path' => 'screening/'.$tutor->id.'/'.$kind.'.pdf',
                'recorded_by_id' => $this->operator->id, 'reviewed_by_id' => $reviewer->id, 'reviewed_at' => now(),
            ]);
        }
    }
}
