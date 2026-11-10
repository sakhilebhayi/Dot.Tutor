<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Models\CouponRedemption;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMoney;
use Tests\Support\FakeCourse;
use Tests\TestCase;

class CouponCreditTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
        FakeCourse::register();
        config(['money.commission.default_percent' => 20]);
    }

    private function coupon($actor, string $url, array $data): void
    {
        $this->actingAs($actor)->postJson($url, $data + ['kind' => 'percent'])->assertCreated();
    }

    public function test_a_tutor_funded_code_lowers_the_commission_base_and_only_applies_to_their_items(): void
    {
        [, , $tutor] = $this->lesson();
        [, , $otherTutor] = $this->lesson();
        $mine = FakeCourse::make($tutor->id, 50000);
        $theirs = FakeCourse::make($otherTutor->id, 40000);
        $learner = $this->makeUser('Learner');
        $this->coupon($tutor, '/api/tutor/money/tutor/coupons', ['code' => 'maths10', 'percent' => 10]);

        $order = $this->placeOrder($learner, [['type' => 'course', 'id' => $mine->id], ['type' => 'course', 'id' => $theirs->id]], 'k', ['coupon_code' => 'MATHS10'])
            ->assertCreated()->assertJsonPath('order.discount.amount_minor', 5000)->assertJsonPath('order.total.amount_minor', 85000)->json('order.id');

        $line = Order::where('uuid', $order)->firstOrFail()->lines()->where('purchasable_id', $mine->id)->firstOrFail();
        $this->assertSame([5000, 0, 45000, 9000, 36000], [$line->tutor_discount_minor, $line->platform_discount_minor, $line->cash_minor, $line->commission_minor, $line->seller_net_minor]);
    }

    public function test_a_platform_funded_code_is_a_promotion_expense_and_the_tutor_earns_in_full(): void
    {
        [, , $tutor] = $this->lesson();
        $course = FakeCourse::make($tutor->id, 50000);
        $learner = $this->makeUser('Learner');
        $this->coupon($this->financeOperator(), '/api/tutor/money/ops/coupons', ['code' => 'WELCOME', 'kind' => 'fixed', 'amount_minor' => 10000]);

        $uuid = $this->placeOrder($learner, [['type' => 'course', 'id' => $course->id]], 'k', ['coupon_code' => 'welcome'])->assertCreated()->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk();
        $this->actingAs($learner)->get('/money/checkout/return?reference='.$this->paystack->lastReference());

        $this->assertSame(40000, $this->paystack->transactions[$this->paystack->lastReference()]['amount']);
        $this->assertSame(40000, Earning::firstOrFail()->net_minor, 'R500 less 20% commission: the tutor is not charged for the platform code.');
        $this->assertSame(10000, app(Ledger::class)->balance(Account::PROMOTIONS_EXPENSE, 'ZAR'));
        $this->assertSame('confirmed', CouponRedemption::firstOrFail()->status);
        $this->assertLedgerHealthy();
    }

    public function test_code_limits_expiry_and_release_on_cancel(): void
    {
        [, , $tutor] = $this->lesson();
        $operator = $this->financeOperator();
        $this->coupon($operator, '/api/tutor/money/ops/coupons', ['code' => 'ONCE', 'percent' => 50, 'max_redemptions' => 1]);
        $this->coupon($operator, '/api/tutor/money/ops/coupons', ['code' => 'SOON', 'percent' => 50, 'starts_at' => now()->addDay()->toIso8601String()]);
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');
        $c1 = FakeCourse::make($tutor->id, 10000);
        $c2 = FakeCourse::make($tutor->id, 10000);

        $uuid = $this->placeOrder($a, [['type' => 'course', 'id' => $c1->id]], 'a1', ['coupon_code' => 'ONCE'])->assertCreated()->json('order.id');
        $this->placeOrder($b, [['type' => 'course', 'id' => $c1->id]], 'b1', ['coupon_code' => 'ONCE'])->assertStatus(422)->assertJsonPath('error', 'coupon_exhausted');
        $this->placeOrder($b, [['type' => 'course', 'id' => $c2->id]], 'b2', ['coupon_code' => 'SOON'])->assertStatus(422)->assertJsonPath('error', 'coupon_invalid');
        $this->placeOrder($b, [['type' => 'course', 'id' => $c2->id]], 'b3', ['coupon_code' => 'NOPE'])->assertStatus(422);

        $this->actingAs($a)->postJson("/api/tutor/money/orders/{$uuid}/cancel")->assertOk();
        $this->placeOrder($b, [['type' => 'course', 'id' => $c1->id]], 'b4', ['coupon_code' => 'ONCE'])->assertCreated();

        $this->travel(2)->days();
        $this->coupon($operator, '/api/tutor/money/ops/coupons', ['code' => 'BRIEF', 'percent' => 5, 'expires_at' => now()->addHour()->toIso8601String()]);
        $this->travel(2)->hours();
        $this->placeOrder($a, [['type' => 'course', 'id' => $c2->id]], 'a2', ['coupon_code' => 'BRIEF'])->assertStatus(422)->assertJsonPath('message', 'That code has expired.');
    }

    public function test_per_person_limit(): void
    {
        [, , $tutor] = $this->lesson();
        $this->coupon($tutor, '/api/tutor/money/tutor/coupons', ['code' => 'MINE', 'percent' => 10, 'per_user_limit' => 1]);
        $learner = $this->makeUser('L');
        $c1 = FakeCourse::make($tutor->id, 10000);
        $c2 = FakeCourse::make($tutor->id, 10000);

        $this->placeOrder($learner, [['type' => 'course', 'id' => $c1->id]], '1', ['coupon_code' => 'MINE'])->assertCreated();
        $this->placeOrder($learner, [['type' => 'course', 'id' => $c2->id]], '2', ['coupon_code' => 'MINE'])->assertStatus(422)->assertJsonPath('error', 'coupon_used');
    }

    public function test_only_approved_tutors_create_codes_and_only_finance_creates_platform_codes(): void
    {
        $learner = $this->makeUser('Not a tutor');

        $this->actingAs($learner)->postJson('/api/tutor/money/tutor/coupons', ['code' => 'FREEBIE', 'kind' => 'percent', 'percent' => 100])->assertForbidden();
        $this->actingAs($learner)->postJson('/api/tutor/money/ops/coupons', ['code' => 'FREEBIE', 'kind' => 'percent', 'percent' => 100])->assertForbidden();
    }

    public function test_promotional_credit_is_granted_by_finance_reserved_by_open_orders_and_spent_once(): void
    {
        [, , $tutor] = $this->lesson();
        $learner = $this->makeUser('L');
        $operator = $this->financeOperator();
        $c1 = FakeCourse::make($tutor->id, 30000);
        $c2 = FakeCourse::make($tutor->id, 30000);

        $this->actingAs($learner)->withHeader('Idempotency-Key', 'g')->postJson('/api/tutor/money/ops/credits', ['user_id' => $learner->id, 'amount_minor' => 20000, 'reason' => 'Welcome'])->assertForbidden();
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'g')->postJson('/api/tutor/money/ops/credits', ['user_id' => $learner->id, 'amount_minor' => 20000, 'reason' => 'Welcome'])->assertCreated();
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'g')->postJson('/api/tutor/money/ops/credits', ['user_id' => $learner->id, 'amount_minor' => 20000, 'reason' => 'Welcome'])->assertOk();

        $this->actingAs($learner)->getJson('/api/tutor/money/credits')->assertJsonPath('balance.amount_minor', 20000);

        $first = $this->placeOrder($learner, [['type' => 'course', 'id' => $c1->id]], 'o1', ['use_credit' => true])
            ->assertCreated()->assertJsonPath('order.credit_applied.amount_minor', 20000)->assertJsonPath('order.total.amount_minor', 10000)->json('order.id');
        $this->placeOrder($learner, [['type' => 'course', 'id' => $c2->id]], 'o2', ['use_credit' => true])
            ->assertCreated()->assertJsonPath('order.credit_applied.amount_minor', 0);
        $this->actingAs($learner)->getJson('/api/tutor/money/credits')->assertJsonPath('available.amount_minor', 0)->assertJsonPath('reserved.amount_minor', 20000);

        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$first}/pay")->assertOk();
        $this->actingAs($learner)->get('/money/checkout/return?reference='.$this->paystack->lastReference());

        $this->actingAs($learner)->getJson('/api/tutor/money/credits')->assertJsonPath('balance.amount_minor', 0);
        $this->assertSame(24000, Earning::firstOrFail()->net_minor, 'Credit is platform money: the tutor earns on the full price.');
        $this->assertLedgerHealthy();
    }

    public function test_an_order_fully_covered_by_credit_settles_without_paystack(): void
    {
        [, , $tutor] = $this->lesson();
        $learner = $this->makeUser('L');
        $course = FakeCourse::make($tutor->id, 15000);
        $this->actingAs($this->financeOperator())->withHeader('Idempotency-Key', 'g')->postJson('/api/tutor/money/ops/credits', ['user_id' => $learner->id, 'amount_minor' => 20000, 'reason' => 'Bursary'])->assertCreated();

        $uuid = $this->placeOrder($learner, [['type' => 'course', 'id' => $course->id]], 'o', ['use_credit' => true])->assertJsonPath('order.total.amount_minor', 0)->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->assertJsonPath('status', 'settled');

        $this->assertSame(0, $this->paystack->calls('initialize'));
        $this->actingAs($learner)->getJson('/api/tutor/money/credits')->assertJsonPath('balance.amount_minor', 5000);
        $this->assertLedgerHealthy();
    }

    public function test_finance_can_take_back_unused_credit_but_not_more(): void
    {
        $learner = $this->makeUser('L');
        $operator = $this->financeOperator();
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'g')->postJson('/api/tutor/money/ops/credits', ['user_id' => $learner->id, 'amount_minor' => 5000, 'reason' => 'Promo'])->assertCreated();

        $this->actingAs($operator)->withHeader('Idempotency-Key', 'r1')->postJson('/api/tutor/money/ops/credits/revoke', ['user_id' => $learner->id, 'amount_minor' => 6000, 'reason' => 'Error'])->assertStatus(422);
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'r2')->postJson('/api/tutor/money/ops/credits/revoke', ['user_id' => $learner->id, 'amount_minor' => 5000, 'reason' => 'Error'])->assertOk()->assertJsonPath('balance.amount_minor', 0);
        $this->assertLedgerHealthy();
    }
}
