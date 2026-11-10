<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class SweepAndReconciliationTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
    }

    public function test_the_sweep_credits_a_payer_who_closed_the_tab_and_expires_the_rest(): void
    {
        [$s1, $l1] = $this->lesson();
        [$s2, $l2] = $this->lesson();
        $paidUuid = $this->placeOrder($l1, [$this->bookingItem($s1)], 'a')->json('order.id');
        $this->actingAs($l1)->postJson("/api/tutor/money/orders/{$paidUuid}/pay");
        $abandonedUuid = $this->placeOrder($l2, [$this->bookingItem($s2)], 'b')->json('order.id');
        $this->actingAs($l2)->postJson("/api/tutor/money/orders/{$abandonedUuid}/pay");
        $this->paystack->verifyOverrides[Payment::where('order_id', Order::where('uuid', $abandonedUuid)->value('id'))->value('reference')] = ['status' => 'abandoned'];

        $this->travel(2)->hours();
        $this->artisan('money:settle')->assertSuccessful();

        $this->assertSame(Order::PAID, Order::where('uuid', $paidUuid)->value('status'));
        $this->assertSame(Order::EXPIRED, Order::where('uuid', $abandonedUuid)->value('status'));
        $this->assertSame(Payment::ABANDONED, Payment::where('order_id', Order::where('uuid', $abandonedUuid)->value('id'))->value('status'));
        $this->assertLedgerHealthy();
    }

    public function test_the_sweep_processes_a_stored_webhook_that_failed_the_first_time(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay");
        $payment = Payment::firstOrFail();

        $this->paystack->down = true;
        $this->signedWebhook(['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => $payment->reference, 'status' => 'success', 'amount' => $payment->amount_minor, 'currency' => 'ZAR']])->assertOk();
        $this->assertSame(Order::PENDING_PAYMENT, Order::firstOrFail()->status, 'Paystack down: nothing settled.');

        $this->paystack->down = false;
        $this->travel(6)->minutes();
        $this->artisan('money:settle')->assertSuccessful();

        $this->assertSame(Order::PAID, Order::firstOrFail()->status);
        $this->assertContains(WebhookEvent::firstOrFail()->status, ['processed']);
    }

    public function test_reconciliation_is_clean_after_real_flows_and_catches_a_tampered_ledger(): void
    {
        $this->paidLessonOrder();
        $this->artisan('money:reconcile', ['--from' => now()->subDay()->toDateString(), '--to' => now()->toDateString()])->assertSuccessful();

        // Someone writes to the earnings table directly: reconciliation must notice.
        DB::table('money_earnings')->update(['net_minor' => 1]);
        $this->artisan('money:reconcile', ['--from' => now()->subDay()->toDateString(), '--to' => now()->toDateString(), '--record' => true])->assertFailed();
        $this->assertSame('reconciliation', FinanceIssue::firstOrFail()->kind);
    }

    public function test_reconciliation_compares_a_paystack_export(): void
    {
        $order = $this->paidLessonOrder();
        $reference = $order->payments()->first()->reference;
        $path = tempnam(sys_get_temp_dir(), 'ps');
        file_put_contents($path, "Reference,Amount\n{$reference},300.00\nunknown-ref,10.00\n");

        $this->artisan('money:reconcile', ['--paystack-csv' => $path])->expectsOutputToContain('Paystack rows matched: 1')->assertFailed();
        unlink($path);
    }

    public function test_the_schedule_runs_the_money_commands(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('money:settle')->expectsOutputToContain('money:reconcile')->assertSuccessful();
    }

    public function test_payout_drafting_waits_for_the_payout_day_unless_forced(): void
    {
        $this->travelTo(now()->startOfWeek()->addDays(2));
        $this->artisan('money:draft-payouts')->expectsOutput('Not the payout day.')->assertSuccessful();
        $this->artisan('money:draft-payouts --force')->expectsOutputToContain('payout(s)')->assertSuccessful();
        $this->assertSame(0, Earning::count());
    }
}
