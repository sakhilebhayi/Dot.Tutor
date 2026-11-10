<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\Events\StudyGroupUpdated;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Services\LibraryService;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Payout;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Services\DatabaseReportSink;
use App\Domain\Safety\Services\Incidents;
use App\Domain\Safety\Services\Moderation;
use App\Models\User;
use App\Services\TutorModeration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The event-to-notification matrix beyond bookings: money, learning, verification, safety and study groups, each
 * driven through the owning domain's real service or row (comms adds listeners only).
 */
class DomainSourcesTest extends TestCase
{
    use BuildsComms;
    use RefreshDatabase;

    private function order(User $buyer, int $total = 25000, string $status = Order::PAID): Order
    {
        return Order::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'buyer_id' => $buyer->id, 'status' => $status, 'currency' => 'ZAR',
            'subtotal_minor' => $total, 'total_minor' => $total, 'paid_at' => now(),
        ]);
    }

    public function test_a_paid_order_confirms_payment_to_the_buyer_and_the_guardian_of_a_minor(): void
    {
        $teen = $this->teen('Thandi Nkosi');
        $guardian = $this->guardianFor($teen);
        $order = $this->order($teen);
        DB::table('money_order_lines')->insert(['order_id' => $order->id, 'purchasable_type' => 'course', 'purchasable_id' => 1, 'title' => 'Grade 11 Physics',
            'seller_id' => null, 'commission_category' => 'course', 'earning_release' => 'after_window', 'price_minor' => 25000, 'cash_minor' => 25000,
            'commission_bps' => 1500, 'commission_minor' => 3750, 'seller_net_minor' => 21250, 'created_at' => now(), 'updated_at' => now()]);

        OrderPaid::dispatch($order->id);
        OrderPaid::dispatch($order->id);

        $notice = $this->inbox($teen, 'payment_received')->sole();
        $this->assertStringContainsString('Grade 11 Physics', $notice->data['message']);
        $this->assertSame('payments', $notice->data['category']);
        $this->assertSame('Thandi\'s purchase is paid', $this->inbox($guardian, 'payment_received')->sole()->data['title']);
    }

    public function test_a_sale_tells_the_seller_without_naming_the_buyer(): void
    {
        $buyer = $this->adult('Buyer Person');
        $seller = $this->makeTutor()->user;
        $order = $this->order($buyer);
        DB::table('money_order_lines')->insert(['order_id' => $order->id, 'purchasable_type' => 'resource', 'purchasable_id' => 1, 'title' => 'Exam pack',
            'seller_id' => $seller->id, 'commission_category' => 'resource', 'earning_release' => 'after_window', 'price_minor' => 10000, 'cash_minor' => 10000,
            'commission_bps' => 1500, 'commission_minor' => 1500, 'seller_net_minor' => 8500, 'created_at' => now(), 'updated_at' => now()]);

        OrderPaid::dispatch($order->id);

        $sale = $this->inbox($seller, 'sale_made')->sole();
        $this->assertStringContainsString('Exam pack', $sale->data['message']);
        $this->assertStringNotContainsString('Buyer Person', json_encode($sale->data));
    }

    public function test_a_declined_payment_is_reported_once(): void
    {
        $buyer = $this->adult('Buyer');
        $order = $this->order($buyer, 25000, Order::PENDING_PAYMENT);
        $payment = Payment::query()->forceCreate(['order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'TUT-X1', 'attempt' => 1,
            'amount_minor' => 25000, 'currency' => 'ZAR', 'status' => Payment::INITIALISED]);

        $payment->forceFill(['status' => Payment::FAILED])->save();
        $payment->forceFill(['failure_message' => 'Declined'])->save();

        $notice = $this->inbox($buyer, 'payment_failed')->sole();
        $this->assertSame('/money/checkout/'.$order->uuid, $notice->data['url']);
    }

    public function test_payouts_drafted_paid_and_failed_reach_the_tutor(): void
    {
        $tutor = $this->makeTutor()->user;
        $batch = DB::table('money_payout_batches')->insertGetId(['status' => 'draft', 'period_end' => now(), 'currency' => 'ZAR', 'created_at' => now(), 'updated_at' => now()]);
        $payout = Payout::query()->forceCreate(['batch_id' => $batch, 'payee_id' => $tutor->id, 'reference' => 'PO-1', 'amount_minor' => 150000, 'currency' => 'ZAR', 'status' => Payout::DRAFT]);
        $failing = Payout::query()->forceCreate(['batch_id' => $batch, 'payee_id' => $tutor->id, 'reference' => 'PO-2', 'amount_minor' => 50000, 'currency' => 'ZAR', 'status' => Payout::DRAFT]);

        $payout->forceFill(['status' => Payout::PAID, 'paid_at' => now()])->save();
        $failing->forceFill(['status' => Payout::FAILED, 'failure_message' => 'Account closed'])->save();

        $this->assertCount(2, $this->inbox($tutor, 'payout_drafted'));
        $this->assertStringContainsString('PO-1', $this->inbox($tutor, 'payout_paid')->sole()->data['message']);
        $this->assertStringNotContainsString('Account closed', $this->inbox($tutor, 'payout_failed')->sole()->data['message']);
    }

    public function test_tutor_application_decisions_come_through_the_existing_notification(): void
    {
        $profile = $this->makeTutor(['status' => 'pending']);
        app(TutorModeration::class)->approve($profile, $this->makeOperator());

        $notice = $this->inbox($profile->user)->sole();
        $this->assertSame('tutor_application_decided', $notice->data['type']);
        $this->assertSame('Your tutor application', $notice->data['title']);
        $this->assertSame(1, NotificationDelivery::where('user_id', $profile->user_id)->where('channel', 'in_app')->count());
        $this->assertNotNull($this->delivery($profile->user, 'tutor_application_decided', 'email'));
    }

    public function test_a_verification_decision_is_explained_to_the_tutor(): void
    {
        $profile = $this->makeTutor();
        $operator = $this->makeOperator();
        VerificationDecision::create(['tutor_profile_id' => $profile->id, 'operator_id' => $operator->id, 'subject' => 'level', 'from_value' => null, 'to_value' => null, 'reason' => 'Evidence expired.']);

        $notice = $this->inbox($profile->user, 'tutor_verification_decided')->sole();
        $this->assertSame('Verification level removed', $notice->data['title']);
        $this->assertStringContainsString('Evidence expired.', $notice->data['message']);
    }

    public function test_a_reporter_hears_their_report_was_reviewed_without_details_about_the_other_person(): void
    {
        $reporter = $this->adult('Reporter');
        $reported = $this->adult('Reported Person');
        $report = app(DatabaseReportSink::class)->report($reporter, $reported, ReportCategory::Spam, 'Selling answers.');

        app(Moderation::class)->act($this->operator(), 'warn', $report, null, $reported, 'Spam.');

        $notice = $this->inbox($reporter, 'report_updated')->sole();
        $this->assertStringContainsString('took action', $notice->data['message']);
        $this->assertStringNotContainsString('Reported Person', json_encode($notice->data));
        $this->assertSame('account_warned', $this->inbox($reported)->sole()->data['type']);
    }

    public function test_a_safeguarding_incident_alerts_operators_at_once_ignoring_quiet_hours(): void
    {
        $this->configurePush();
        \Illuminate\Support\Facades\Http::fake(['fcm.googleapis.com/*' => \Illuminate\Support\Facades\Http::response('', 201)]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-12 23:30', 'Africa/Johannesburg'));
        $operator = $this->operator('Officer');
        $this->subscribeBrowser($operator);
        $reporter = $this->adult('Reporter');
        $report = app(DatabaseReportSink::class)->report($reporter, $this->teen('Teen'), ReportCategory::from('inappropriate_contact_minor'), 'Asked for a phone number.');

        $alert = $this->inbox($operator, 'incident_alert')->first();
        $this->assertNotNull($alert, 'a critical report opens an incident and alerts operators');
        $this->assertSame('safety', $alert->data['category']);
        $this->assertSame(NotificationDelivery::SENT, $this->delivery($operator, 'incident_alert', 'push')->status, 'urgent: not held for quiet hours');
        $this->assertNotNull($report->fresh()->incident_id);
    }

    public function test_a_new_learner_in_a_course_is_one_notice_per_course_for_the_creator(): void
    {
        $owner = $this->makeTutor()->user;
        $course = Course::query()->forceCreate(['owner_id' => $owner->id, 'slug' => 'physics-11', 'title' => 'Physics 11', 'status' => 'published', 'pricing' => 'free']);

        foreach (['One', 'Two', 'Three'] as $name) {
            $learner = $this->adult($name);
            Enrolment::create(['course_id' => $course->id, 'user_id' => $learner->id, 'source' => 'free', 'status' => 'active']);
        }

        $notices = $this->inbox($owner, 'course_new_learner');
        $this->assertCount(1, $notices, 'unread notices about one course replace each other');
        $this->assertStringContainsString('3 learners are enrolled', $notices->sole()->data['message']);
        $this->assertStringNotContainsString('Three', json_encode($notices->sole()->data));
        // The learner enrolled themselves: an inbox entry only, no email.
        $this->assertNull($this->delivery($learner, 'course_enrolled', 'email'));
        $this->assertCount(1, $this->inbox($learner, 'course_enrolled'));
    }

    public function test_a_resource_taken_down_and_restored_tells_its_owner(): void
    {
        $owner = $this->makeTutor()->user;
        $resource = LibraryResource::query()->forceCreate(['owner_id' => $owner->id, 'title' => 'Algebra notes', 'type' => 'notes', 'ownership' => 'own_work',
            'licence' => 'cc_by', 'status' => 'published', 'path' => 'x', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64), 'original_name' => 'a.pdf']);
        $operator = $this->makeOperator();

        app(LibraryService::class)->review($operator, $resource, 'approved', null);
        $this->assertSame('Your resource passed review', $this->inbox($owner, 'resource_approved')->sole()->data['title']);

        $resource->refresh()->forceFill(['status' => 'taken_down', 'takedown_reason' => 'Copyright notice.', 'taken_down_at' => now()])->save();
        $this->assertStringContainsString('Copyright notice.', $this->inbox($owner, 'resource_taken_down')->sole()->data['message']);

        $resource->refresh()->forceFill(['status' => 'published', 'takedown_reason' => null, 'taken_down_at' => null])->save();
        $this->assertCount(1, $this->inbox($owner, 'resource_restored'));
    }

    public function test_a_study_group_update_reaches_members_except_whoever_posted_it(): void
    {
        $poster = $this->adult('Poster');
        $member = $this->adult('Member');
        $teen = $this->teen('Teen');

        StudyGroupUpdated::dispatch(4, 'Grade 11 Maths', 'session_scheduled', 'Revision on Thursday at 16:00.', [$poster->id, $member->id, $teen->id], $poster->id, '/rooms/4');
        StudyGroupUpdated::dispatch(4, 'Grade 11 Maths', 'session_changed', 'Moved to 17:00.', [$poster->id, $member->id, $teen->id], $poster->id, '/rooms/4');

        $this->assertCount(0, $this->inbox($poster));
        $this->assertCount(1, $this->inbox($member, 'study_group_update'), 'unread updates about one group collapse to the latest');
        $this->assertSame('Session changed in Grade 11 Maths', $this->inbox($member)->sole()->data['title']);
        $this->assertCount(1, $this->inbox($teen));
    }
}
