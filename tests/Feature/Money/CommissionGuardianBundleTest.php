<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Contracts\BuyerAge;
use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Money\Models\CommissionRule;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Services\Earnings;
use App\Domain\Money\Services\Orders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsMoney;
use Tests\Support\FakeCourse;
use Tests\TestCase;

class CommissionGuardianBundleTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
        FakeCourse::register();
        config(['money.commission.default_percent' => 15]);
    }

    public function test_the_tutor_sees_price_commission_charges_and_net_before_publishing(): void
    {
        config(['money.charges.fixed_fee_minor' => 200]);
        [, , $tutor] = $this->lesson();

        $this->actingAs($tutor)->postJson('/api/tutor/money/commission/preview', ['price_minor' => 25000, 'category' => 'lesson'])->assertOk()
            ->assertJsonPath('price.amount_minor', 25000)
            ->assertJsonPath('commission.percent', 15)
            ->assertJsonPath('commission.amount.amount_minor', 3750)
            ->assertJsonPath('other_charges.0.amount.amount_minor', 200)
            ->assertJsonPath('net.amount_minor', 21050)
            ->assertJsonPath('net.formatted', "R\u{202F}210.50");
    }

    public function test_commission_rules_are_versioned_and_category_overrides_win(): void
    {
        $operator = $this->financeOperator();
        [, , $tutor] = $this->lesson();

        $this->actingAs($tutor)->postJson('/api/tutor/money/ops/commission-rules', ['percent' => 1, 'reason' => 'me'])->assertForbidden();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/commission-rules', ['percent' => 18, 'reason' => 'Platform default 2027'])->assertCreated()->assertJsonPath('rule.version', 1);
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/commission-rules', ['category' => 'course', 'percent' => 25, 'reason' => 'Courses'])->assertCreated();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/commission-rules', ['category' => 'course', 'percent' => 22.5, 'reason' => 'Courses revised'])->assertCreated()->assertJsonPath('rule.version', 2);

        $this->actingAs($tutor)->postJson('/api/tutor/money/commission/preview', ['price_minor' => 10000, 'category' => 'course'])
            ->assertJsonPath('commission.percent', 22.5)->assertJsonPath('commission.rule_version', 2);
        $this->actingAs($tutor)->postJson('/api/tutor/money/commission/preview', ['price_minor' => 10000, 'category' => 'lesson'])
            ->assertJsonPath('commission.percent', 18)->assertJsonPath('commission.rule_category', null);

        $old = CommissionRule::where('category', 'course')->where('version', 1)->firstOrFail();
        $this->assertNotNull($old->superseded_at);
        $this->expectException(LogicException::class);
        $old->forceFill(['percent_bps' => 1])->save();
    }

    public function test_an_order_line_keeps_the_rule_it_was_priced_with(): void
    {
        [$session, $learner] = $this->lesson();
        $operator = $this->financeOperator();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/commission-rules', ['category' => 'lesson', 'percent' => 10, 'reason' => 'Launch'])->assertCreated();
        $order = $this->paidLessonOrder($session, $learner);
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/commission-rules', ['category' => 'lesson', 'percent' => 30, 'reason' => 'Later'])->assertCreated();

        $line = $order->lines()->firstOrFail();
        $this->assertSame([1000, 3000], [$line->commission_bps, $line->commission_minor]);
        $this->assertSame(27000, Earning::firstOrFail()->net_minor);
    }

    public function test_a_minor_is_held_for_a_guardian_and_cannot_pay_until_approved(): void
    {
        $this->useMoneysOwnApprovalRule();
        $this->app->instance(BuyerAge::class, new class implements BuyerAge
        {
            public function bandFor(User $user): ?string
            {
                return 'teen';
            }
        });
        [$session, $learner] = $this->lesson();

        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->assertCreated()
            ->assertJsonPath('order.status', 'awaiting_guardian')->assertJsonPath('order.guardian_status', 'pending')->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->assertJsonPath('status', 'held_for_guardian');
        $this->assertSame(0, $this->paystack->calls('initialize'));

        app(Orders::class)->recordGuardianDecision(Order::where('uuid', $uuid)->firstOrFail(), true, 'approval-1');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->assertJsonPath('status', 'redirect');
    }

    public function test_a_guardian_refusal_closes_the_order(): void
    {
        $this->useMoneysOwnApprovalRule();
        config(['money.guardian.hold_unknown_age' => true]);
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->assertJsonPath('order.status', 'awaiting_guardian')->json('order.id');

        app(Orders::class)->recordGuardianDecision(Order::where('uuid', $uuid)->firstOrFail(), false);

        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertStatus(409)->assertJsonPath('error', 'order_closed');
        $this->assertSame(Order::GUARDIAN_REFUSED, Order::firstOrFail()->status);
    }

    public function test_an_adult_of_unknown_age_passes_by_default(): void
    {
        [$session, $learner] = $this->lesson();

        $this->placeOrder($learner, [$this->bookingItem($session)])->assertJsonPath('order.status', 'pending_payment')->assertJsonPath('order.guardian_status', 'not_needed');
    }

    public function test_a_bundle_grants_every_item_and_the_tutor_earns_after_the_refund_window(): void
    {
        config(['money.earnings.refund_window_days.default' => 7]);
        [, , $tutor] = $this->lesson();
        $tutor->tutorProfile->forceFill(['status' => 'approved'])->save();
        [, , $other] = $this->lesson();
        $c1 = FakeCourse::make($tutor->id, 20000);
        $c2 = FakeCourse::make($tutor->id, 20000);
        $foreign = FakeCourse::make($other->id, 20000);
        $learner = $this->makeUser('L');

        $this->actingAs($tutor)->postJson('/api/tutor/money/tutor/bundles', ['title' => 'Exam pack', 'price_minor' => 30000, 'items' => [['type' => 'course', 'id' => $c1->id], ['type' => 'course', 'id' => $foreign->id]]])
            ->assertStatus(422)->assertJsonPath('error', 'item_not_yours');
        $bundleId = $this->actingAs($tutor)->postJson('/api/tutor/money/tutor/bundles', ['title' => 'Exam pack', 'price_minor' => 30000, 'items' => [['type' => 'course', 'id' => $c1->id], ['type' => 'course', 'id' => $c2->id]]])
            ->assertCreated()->json('bundle.id');

        $uuid = $this->placeOrder($learner, [['type' => 'bundle', 'id' => $bundleId]])->assertCreated()->assertJsonPath('order.total.amount_minor', 30000)->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay");
        $this->actingAs($learner)->get('/money/checkout/return?reference='.$this->paystack->lastReference());

        $check = app(EntitlementCheck::class);
        $this->assertSame('bundle', $check->source($learner->id, 'course', $c1->id));
        $this->assertTrue($check->owns($learner->id, 'course', $c2->id));
        $this->assertFalse($check->owns($learner->id, 'course', $foreign->id));
        $this->placeOrder($learner, [['type' => 'course', 'id' => $c1->id]], 'again')->assertStatus(409)->assertJsonPath('error', 'already_purchased');

        app(Earnings::class)->releaseDue(now()->addDays(6));
        $this->assertSame(Earning::PENDING, Earning::firstOrFail()->status);
        $this->travel(8)->days();
        app(Earnings::class)->releaseDue();
        $this->assertSame(Earning::AVAILABLE, Earning::firstOrFail()->status);
        $this->assertLedgerHealthy();
    }

    public function test_free_items_are_granted_as_free_without_a_payment(): void
    {
        [, , $tutor] = $this->lesson();
        $free = FakeCourse::make($tutor->id, 0);
        $learner = $this->makeUser('L');

        $uuid = $this->placeOrder($learner, [['type' => 'course', 'id' => $free->id]])->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->assertJsonPath('status', 'settled');

        $this->actingAs($learner)->getJson('/api/tutor/money/entitlements/check?type=course&id='.$free->id)->assertOk()->assertJsonPath('owns', true)->assertJsonPath('source', 'free');
        $this->assertSame(0, $this->paystack->calls('initialize'));
        $this->assertLedgerHealthy();
    }

    /** Money's default rule (age band only). The integration provider binds the safety-backed rule (D-1400). */
    private function useMoneysOwnApprovalRule(): void
    {
        $this->app->bind(\App\Domain\Money\Contracts\PurchaseApprovals::class, \App\Domain\Money\Seams\AgeBandPurchaseApprovals::class);
    }
}
