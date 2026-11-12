<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
        config(['money.commission.default_percent' => 15]);
    }

    public function test_an_order_needs_an_idempotency_key(): void
    {
        [$session, $learner] = $this->lesson();

        $this->actingAs($learner)->postJson('/api/tutor/money/orders', ['items' => [$this->bookingItem($session)]])
            ->assertStatus(428)->assertJsonPath('error', 'idempotency_key_required');
        $this->assertSame(0, Order::count());
    }

    public function test_the_price_comes_from_the_server_and_the_quote_shows_it_before_committing(): void
    {
        [$session, $learner] = $this->lesson(300, 90);

        $this->actingAs($learner)->postJson('/api/tutor/money/quote', ['items' => [$this->bookingItem($session) + ['price_minor' => 1]]])
            ->assertOk()
            ->assertJsonPath('total.amount_minor', 45000)
            ->assertJsonPath('total.formatted', "R450.00")
            ->assertJsonPath('lines.0.type', 'booking');
        $this->assertSame(0, Order::count());
    }

    public function test_a_double_click_creates_one_order(): void
    {
        [$session, $learner] = $this->lesson();

        $first = $this->placeOrder($learner, [$this->bookingItem($session)], 'same-key')->assertCreated();
        $second = $this->placeOrder($learner, [$this->bookingItem($session)], 'same-key')->assertOk();

        $this->assertSame($first->json('order.id'), $second->json('order.id'));
        $this->assertSame(1, Order::count());
    }

    public function test_a_second_order_for_the_same_lesson_is_refused_while_the_first_is_open(): void
    {
        [$session, $learner] = $this->lesson();

        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)], 'a')->json('order.id');
        $this->placeOrder($learner, [$this->bookingItem($session)], 'b')
            ->assertStatus(409)->assertJsonPath('error', 'already_in_open_order')->assertJsonPath('order_id', $uuid);
    }

    public function test_only_the_learner_who_booked_can_buy_the_lesson(): void
    {
        [$session] = $this->lesson();
        $stranger = $this->makeUser('Stranger');

        $this->placeOrder($stranger, [$this->bookingItem($session)])->assertStatus(409)->assertJsonPath('error', 'item_unavailable');
    }

    public function test_paying_twice_opens_one_paystack_checkout(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');

        $one = $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->json('redirect_url');
        $two = $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->json('redirect_url');

        $this->assertSame($one, $two);
        $this->assertStringStartsWith('https://checkout.paystack.com/', $one);
        $this->assertSame(1, $this->paystack->calls('initialize'));
        $this->assertSame(1, Payment::count());
    }

    public function test_a_verified_payment_settles_the_order_once_with_a_balanced_ledger(): void
    {
        [$session, $learner, $tutor] = $this->lesson();
        $order = $this->paidLessonOrder($session, $learner);

        $this->assertSame(Order::PAID, $order->status);
        $payment = Payment::firstOrFail();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertSame(150, $payment->fees_minor);

        $earning = Earning::firstOrFail();
        $this->assertSame([30000, 4500, 25500, Earning::PENDING], [$earning->gross_minor, $earning->commission_minor, $earning->net_minor, $earning->status]);

        $ledger = app(Ledger::class);
        $this->assertSame(25500, $ledger->balance(Account::tutorPending($tutor->id), 'ZAR'));
        $this->assertSame(4500, $ledger->balance(Account::PLATFORM_COMMISSION, 'ZAR'));
        $this->assertSame(29850, $ledger->balance(Account::GATEWAY_CLEARING, 'ZAR'));
        $this->assertSame(150, $ledger->balance(Account::GATEWAY_FEES, 'ZAR'));

        $this->assertTrue(app(EntitlementCheck::class)->owns($learner->id, 'booking', $session->id));
        $this->assertSame('TUT-00000001', Receipt::firstOrFail()->number);

        // Returning again (refresh) verifies again but settles nothing twice.
        $this->actingAs($learner)->get('/money/checkout/return?reference='.$payment->reference)->assertRedirect();
        $this->assertSame(1, Earning::count());
        $this->assertSame(1, Receipt::count());
        $this->assertLedgerHealthy();
    }

    public function test_a_charge_for_a_different_amount_is_not_accepted(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk();
        $reference = $this->paystack->lastReference();
        $this->paystack->verifyOverrides[$reference] = ['amount' => 100];

        $this->actingAs($learner)->get('/money/checkout/return?reference='.$reference);

        $this->assertSame(Order::PENDING_PAYMENT, Order::firstOrFail()->status);
        $this->assertSame('amount_mismatch', Payment::firstOrFail()->failure_code);
        $this->assertSame('payment_mismatch', FinanceIssue::firstOrFail()->kind);
        $this->assertSame(0, Earning::count());
    }

    public function test_a_failed_charge_leaves_the_order_payable(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay");
        $this->paystack->verifyOverrides[$this->paystack->lastReference()] = ['status' => 'failed'];

        $this->actingAs($learner)->get('/money/checkout/return?reference='.$this->paystack->lastReference());

        $this->assertSame(Payment::FAILED, Payment::firstOrFail()->status);
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->assertJsonPath('status', 'redirect');
        $this->assertSame(2, Payment::count());
    }

    public function test_without_paystack_checkout_says_payment_is_not_available(): void
    {
        config(['money.paystack.secret_key' => null]);
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');

        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")
            ->assertOk()->assertJsonPath('status', 'unavailable');
        $this->assertSame(0, $this->paystack->calls('initialize'));
    }

    public function test_a_live_key_is_refused_without_the_second_switch(): void
    {
        config(['money.paystack.secret_key' => 'sk_live_abc']);
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');

        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertJsonPath('status', 'unavailable');
        $this->assertSame(0, $this->paystack->calls('initialize'));

        config(['money.paystack.allow_live' => true]);
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertJsonPath('status', 'redirect');
    }

    public function test_someone_else_cannot_see_or_pay_an_order(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');
        $other = $this->makeUser('Other');

        $this->actingAs($other)->getJson("/api/tutor/money/orders/{$uuid}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertForbidden();
        $this->actingAs($other)->postJson("/api/tutor/money/orders/{$uuid}/cancel")->assertForbidden();
    }

    public function test_a_cancelled_order_releases_the_lesson_for_a_new_order(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)], 'a')->json('order.id');

        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/cancel")->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->placeOrder($learner, [$this->bookingItem($session)], 'b')->assertCreated();
    }

    public function test_a_lesson_already_paid_cannot_be_bought_again(): void
    {
        [$session, $learner] = $this->lesson();
        $this->paidLessonOrder($session, $learner);

        $this->placeOrder($learner, [$this->bookingItem($session)], 'again')->assertStatus(409)->assertJsonPath('error', 'already_purchased');
    }

    public function test_a_lesson_cancelled_before_payment_cannot_be_paid(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');
        $session->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertStatus(409)->assertJsonPath('error', 'item_unavailable');
        $this->assertSame(Order::CANCELLED, Order::firstOrFail()->status);
        $this->assertSame(0, $this->paystack->calls('initialize'));
    }
}
