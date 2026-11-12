<?php

namespace Tests\Feature\Security;

use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Integration\Listeners\RecordRefund;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Money\Events\OrderRefunded;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Services\Earnings;
use App\Domain\Security\Notifications\PayoutAccountChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMoney;
use Tests\Concerns\SeedsAcademicStructure;
use Tests\TestCase;

/**
 * R-47 security S-10 to S-13: money that could move to the wrong person or access that outlived a refund.
 */
class MoneyIntegrityTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;
    use SeedsAcademicStructure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
        config(['money.commission.default_percent' => 15, 'money.payouts.minimum_minor' => 10000, 'money.earnings.hold_hours_after_lesson' => 24]);
    }

    private function bank(User $tutor, string $number, bool $recent = true)
    {
        $this->flushSession();
        $request = $recent ? $this->actingAs($tutor)->withSession(['tutor.signed_in_at' => time()]) : $this->actingAs($tutor);

        return $request->putJson('/api/tutor/money/tutor/payout-account', [
            'holder_name' => 'T Tutor', 'account_number' => $number, 'bank_name' => 'First National Bank', 'bank_code' => '250655',
        ]);
    }

    public function test_a_lesson_disputed_during_the_hold_is_not_paid_out(): void
    {
        [$session, $learner] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $session->forceFill(['status' => 'completed', 'ended_at' => now()])->save();

        app(Earnings::class)->releaseDue(now()); // the sweep sees "completed" and starts the hold
        $this->assertNotNull(Earning::firstOrFail()->release_after);

        $session->forceFill(['status' => 'disputed'])->save(); // the learner complains within the hold

        $this->travel(25)->hours();
        app(Earnings::class)->releaseDue();

        $this->assertSame(Earning::PENDING, Earning::firstOrFail()->status);
        $this->assertSame('delivery_needs_review', FinanceIssue::firstOrFail()->kind);
    }

    public function test_bank_details_need_a_recent_sign_in_and_a_changed_account_waits(): void
    {
        Notification::fake();
        [$session, $learner, $tutor] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $session->forceFill(['status' => 'completed', 'ended_at' => now()])->save();

        $this->bank($tutor, '62001234567')->assertOk();
        Notification::assertNotSentTo($tutor, PayoutAccountChanged::class); // a first account is not a change

        $this->bank($tutor, '99887766554', recent: false)->assertStatus(423)->assertJsonPath('error', 'reauthentication_required');

        $this->travel(2)->days();
        app(Earnings::class)->releaseDue();
        $operator = $this->financeOperator();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()->assertJsonPath('batch.payout_count', 1);
        $payout = Payout::firstOrFail();

        // A stolen session changes the account: the drafted payout returns to the next batch and the tutor is told.
        $this->bank($tutor, '99887766554')->assertOk();
        $this->assertSame(Payout::FAILED, $payout->fresh()->status);
        Notification::assertSentTo($tutor, PayoutAccountChanged::class);

        $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()
            ->assertJsonPath('batch.payout_count', 0)
            ->assertJsonPath('batch.carried_forward.0.reason', 'bank_account_recently_changed');

        $this->travel(73)->hours();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()->assertJsonPath('batch.payout_count', 1);
    }

    public function test_a_fully_refunded_package_stops_covering_lessons(): void
    {
        [$session, $learner, , $profile] = $this->lesson();
        $offering = TutorOffering::query()->forceCreate([
            'tutor_profile_id' => $profile->id, 'type' => 'package', 'title' => '4 lessons', 'price_minor' => 100000, 'currency' => 'ZAR',
            'duration_minutes' => 60, 'format' => 'online', 'capacity' => 1, 'package_lessons' => 4,
        ]);
        $purchase = TutorPackagePurchase::query()->forceCreate([
            'tutor_offering_id' => $offering->id, 'tutor_profile_id' => $profile->id, 'learner_id' => $learner->id, 'lessons_total' => 4, 'lessons_used' => 1,
            'lesson_minutes' => 60, 'price_minor' => 100000, 'currency' => 'ZAR', 'status' => TutorPackagePurchase::ACTIVE,
        ]);

        $this->refund($learner, 'package', $purchase->id, $profile->user_id);

        $this->assertSame(TutorPackagePurchase::CANCELLED, $purchase->fresh()->status);
    }

    public function test_a_fully_refunded_room_place_ends_and_frees_its_seat(): void
    {
        $tutor = $this->makeUser('Room tutor');
        $learner = $this->makeUser('Learner');
        $this->seedAcademic();
        $offering = SubjectOffering::query()->with('level')->firstOrFail();
        $room = Room::query()->forceCreate([
            'curriculum_subject_level_id' => $offering->id, 'curriculum_id' => $offering->level->curriculum_id,
            'academic_level_id' => $offering->academic_level_id, 'subject_id' => $offering->subject_id,
            'kind' => Room::TUTOR, 'audience' => Room::ADULT, 'owner_id' => $tutor->id, 'title' => 'Workshop',
            'language' => 'en', 'join_rule' => 'open', 'capacity' => 5, 'seats_taken' => 1, 'price_minor' => 20000,
            'currency' => 'ZAR', 'status' => 'active',
        ]);
        $member = RoomMember::query()->forceCreate([
            'room_id' => $room->id, 'user_id' => $learner->id, 'role' => RoomMember::LEARNER, 'status' => RoomMember::ACTIVE, 'joined_at' => now(),
        ]);

        $this->refund($learner, 'room', $room->id, $tutor->id);

        $this->assertSame(RoomMember::REMOVED, $member->fresh()->status);
        $this->assertSame(0, $room->fresh()->seats_taken);
    }

    private function refund(User $buyer, string $type, int $id, int $sellerId): void
    {
        $order = Order::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'buyer_id' => $buyer->id, 'status' => 'refunded', 'currency' => 'ZAR',
            'subtotal_minor' => 100000, 'total_minor' => 100000, 'refunded_minor' => 100000,
        ]);
        $line = OrderLine::query()->forceCreate([
            'order_id' => $order->id, 'purchasable_type' => $type, 'purchasable_id' => $id, 'title' => 'Item',
            'seller_id' => $sellerId, 'commission_category' => 'default', 'earning_release' => 'after_window',
            'price_minor' => 100000, 'cash_minor' => 100000, 'commission_bps' => 1500, 'commission_minor' => 15000,
            'seller_net_minor' => 85000, 'refunded_minor' => 100000,
        ]);

        app(RecordRefund::class)->handle(new OrderRefunded($order->id, $line->id, 0, true));
    }
}
