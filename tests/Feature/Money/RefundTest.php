<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Refund;
use App\Domain\Money\Services\Earnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
        config(['money.commission.default_percent' => 15]);
    }

    private function refundUrl(Order $order): string
    {
        return "/api/tutor/money/ops/orders/{$order->uuid}/lines/{$order->lines()->firstOrFail()->id}/refunds";
    }

    public function test_only_finance_operators_can_refund(): void
    {
        [$session, $learner, $tutor] = $this->lesson();
        $order = $this->paidLessonOrder($session, $learner);
        $body = ['amount_minor' => 1000, 'reason' => 'goodwill'];

        $this->actingAs($learner)->withHeader('Idempotency-Key', 'r1')->postJson($this->refundUrl($order), $body)->assertForbidden();
        $this->actingAs($tutor)->withHeader('Idempotency-Key', 'r1')->postJson($this->refundUrl($order), $body)->assertForbidden();

        $operator = $this->financeOperator();
        config(['money.finance_operators' => ['finance@example.com']]);
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'r1')->postJson($this->refundUrl($order), $body)->assertForbidden();

        $this->assertSame(0, Refund::count());
        $this->assertSame(0, $this->paystack->calls('refund'));
    }

    public function test_a_full_refund_reverses_the_sale_revokes_access_and_is_idempotent(): void
    {
        [$session, $learner, $tutor] = $this->lesson();
        $order = $this->paidLessonOrder($session, $learner);
        $operator = $this->financeOperator();

        $this->actingAs($operator)->withHeader('Idempotency-Key', 'full')->postJson($this->refundUrl($order), ['amount_minor' => 30000, 'reason' => 'lesson_cancelled'])
            ->assertCreated()->assertJsonPath('refund.status', 'processed');
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'full')->postJson($this->refundUrl($order), ['amount_minor' => 30000, 'reason' => 'lesson_cancelled'])
            ->assertOk();

        $this->assertSame(1, Refund::count());
        $this->assertSame(1, $this->paystack->calls('refund'));
        $this->assertSame(Order::REFUNDED, $order->fresh()->status);
        $this->assertFalse(app(EntitlementCheck::class)->owns($learner->id, 'booking', $session->id));

        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balance(Account::tutorPending($tutor->id), 'ZAR'));
        $this->assertSame(4500, $ledger->balance(Account::REFUNDS, 'ZAR'));
        $this->assertSame(-150, $ledger->balance(Account::GATEWAY_CLEARING, 'ZAR'), 'Paystack keeps its fee; the platform carries it.');
        $this->assertLedgerHealthy();

        // The pending sale and its reversal cancel out: nothing is ever released to the tutor.
        $session->forceFill(['status' => 'cancelled'])->save();
        app(Earnings::class)->releaseDue(now()->addDays(30));
        $this->assertSame(0, Earning::where('status', Earning::AVAILABLE)->count());
        $this->assertSame(2, Earning::where('status', Earning::CANCELLED)->count());
        $this->assertLedgerHealthy();
    }

    public function test_a_refund_can_never_exceed_what_was_paid(): void
    {
        $order = $this->paidLessonOrder();
        $operator = $this->financeOperator();

        $this->actingAs($operator)->withHeader('Idempotency-Key', 'a')->postJson($this->refundUrl($order), ['amount_minor' => 20000, 'reason' => 'goodwill'])->assertCreated();
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'b')->postJson($this->refundUrl($order), ['amount_minor' => 10001, 'reason' => 'goodwill'])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_amount');
        $this->actingAs($operator)->withHeader('Idempotency-Key', 'c')->postJson($this->refundUrl($order), ['amount_minor' => 10000, 'reason' => 'goodwill'])->assertCreated();

        $this->assertSame(Order::REFUNDED, $order->fresh()->status);
        $this->assertLedgerHealthy();
    }

    public function test_a_refund_needs_an_idempotency_key(): void
    {
        $order = $this->paidLessonOrder();
        $this->flushHeaders();

        $this->actingAs($this->financeOperator())->postJson($this->refundUrl($order), ['amount_minor' => 100, 'reason' => 'goodwill'])->assertStatus(428);
        $this->assertSame(0, Refund::count());
    }

    public function test_a_pending_refund_is_processed_only_when_paystack_confirms_it(): void
    {
        [$session, $learner] = $this->lesson();
        $order = $this->paidLessonOrder($session, $learner);
        $this->paystack->refundStatus = 'pending';
        $this->paystack->refundFetchStatus['9001'] = 'processed';

        $this->actingAs($this->financeOperator())->withHeader('Idempotency-Key', 'p')->postJson($this->refundUrl($order), ['amount_minor' => 5000, 'reason' => 'quality_complaint'])
            ->assertCreated()->assertJsonPath('refund.status', 'pending');
        $this->assertSame(Order::PAID, $order->fresh()->status);

        // The webhook body says processed; the processor fetches the refund from Paystack before acting.
        $this->signedWebhook(['event' => 'refund.processed', 'data' => ['id' => 9001, 'status' => 'processed', 'amount' => 5000, 'transaction_reference' => $order->payments()->first()->reference]])->assertOk();

        $this->assertSame(Refund::PROCESSED, Refund::firstOrFail()->status);
        $this->assertSame(1, $this->paystack->calls('refund_fetch'));
        $this->assertSame(Order::PARTIALLY_REFUNDED, $order->fresh()->status);
        $this->assertTrue(app(EntitlementCheck::class)->owns($learner->id, 'booking', $session->id), 'A partial refund keeps access.');
        $this->assertLedgerHealthy();
    }

    public function test_a_refund_after_the_earning_is_available_comes_out_of_the_available_balance(): void
    {
        [$session, $learner, $tutor] = $this->lesson();
        $order = $this->paidLessonOrder($session, $learner);
        $session->forceFill(['status' => 'completed'])->save();
        app(Earnings::class)->releaseDue(now()->addDays(2));
        $this->assertSame(Earning::AVAILABLE, Earning::firstOrFail()->status);

        $this->actingAs($this->financeOperator())->withHeader('Idempotency-Key', 'late')->postJson($this->refundUrl($order), ['amount_minor' => 10000, 'reason' => 'quality_complaint'])->assertCreated();

        $reversal = Earning::where('kind', Earning::KIND_REVERSAL)->firstOrFail();
        $this->assertSame([-8500, Earning::AVAILABLE], [$reversal->net_minor, $reversal->status]);
        $this->assertSame(17000, app(Ledger::class)->balance(Account::tutorPayable($tutor->id), 'ZAR'));
        $this->assertLedgerHealthy();
    }

    public function test_paystack_refusing_a_refund_is_recorded_and_moves_no_money(): void
    {
        $order = $this->paidLessonOrder();
        $this->paystack->refundStatus = 'failed';

        $this->actingAs($this->financeOperator())->withHeader('Idempotency-Key', 'f')->postJson($this->refundUrl($order), ['amount_minor' => 1000, 'reason' => 'goodwill'])
            ->assertCreated()->assertJsonPath('refund.status', 'failed');

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(0, \App\Domain\Money\Ledger\Models\LedgerJournal::where('kind', 'refund')->count());
        $this->assertLedgerHealthy();
    }
}
