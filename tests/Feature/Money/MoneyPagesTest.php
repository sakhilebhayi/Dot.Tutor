<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class MoneyPagesTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
    }

    public function test_the_checkout_page_shows_the_breakdown_and_sends_the_payer_to_paystack(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');

        $this->actingAs($learner)->get("/money/checkout/{$uuid}")->assertOk()
            ->assertSee('Check your order')->assertSee("R300.00", false)->assertSee('Test mode')->assertSee('Pay R');

        $this->actingAs($learner)->post("/money/checkout/{$uuid}/pay")->assertRedirect('https://checkout.paystack.com/'.$this->paystack->lastReference());
        $this->actingAs($this->makeUser('Other'))->get("/money/checkout/{$uuid}")->assertNotFound();
    }

    public function test_the_checkout_page_is_honest_when_payment_is_off(): void
    {
        config(['money.paystack.secret_key' => null]);
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');

        $this->actingAs($learner)->get("/money/checkout/{$uuid}")->assertOk()
            ->assertSee('Online payment is not enabled')->assertDontSee('with Paystack</button>', false);
    }

    public function test_cancelling_from_the_page(): void
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');

        $this->actingAs($learner)->post("/money/checkout/{$uuid}/cancel")->assertRedirect("/money/checkout/{$uuid}");
        $this->assertSame(Order::CANCELLED, Order::firstOrFail()->status);
    }

    public function test_the_receipt_is_for_the_buyer_and_finance_only_and_shows_vat_only_when_configured(): void
    {
        [$session, $learner] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $receipt = Receipt::firstOrFail();

        $this->actingAs($learner)->get("/money/receipts/{$receipt->id}")->assertOk()->assertSee('TUT-00000001')->assertDontSee('VAT');
        $this->actingAs($this->makeUser('Other'))->get("/money/receipts/{$receipt->id}")->assertNotFound();
        $this->actingAs($this->financeOperator())->get("/money/receipts/{$receipt->id}")->assertOk();

        config(['money.vat.registered' => true, 'money.vat.number' => '4123456789', 'money.operator.legal_name' => 'Example (Pty) Ltd']);
        [$s2, $l2] = $this->lesson();
        $this->paidLessonOrder($s2, $l2);
        $second = Receipt::where('id', '!=', $receipt->id)->firstOrFail();
        $this->assertSame('TUT-00000002', $second->number);
        $this->actingAs($l2)->get("/money/receipts/{$second->id}")->assertOk()->assertSee('VAT number 4123456789')->assertSee('Includes VAT at 15%')->assertSee("R39.13", false);
    }

    public function test_the_receipt_prints_through_a_button_stacks_on_phones_and_names_the_seller_only_when_configured(): void
    {
        // Fix round D-2307: no legal name configured, so nothing stands in for one.
        config(['money.operator.legal_name' => null]);
        [$session, $learner] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $receipt = Receipt::firstOrFail();
        $this->actingAs($learner)->get("/money/receipts/{$receipt->id}")->assertOk()
            ->assertSee('data-td-print', false)->assertSee('Print or save as PDF')->assertSee('data-td-stack', false)
            ->assertSee('data-stack="key">Paid', false)->assertDontSee('Sold by')->assertDontSee('BluePin');

        config(['money.operator.legal_name' => 'Example Learning (Pty) Ltd']);
        [$s2, $l2] = $this->lesson();
        $this->paidLessonOrder($s2, $l2);
        $second = Receipt::where('id', '!=', $receipt->id)->firstOrFail();
        $this->actingAs($l2)->get("/money/receipts/{$second->id}")->assertOk()->assertSee('Sold by <strong>Example Learning (Pty) Ltd</strong>', false);
    }

    public function test_tutor_earnings_page_and_finance_page_access(): void
    {
        [$session, $learner, $tutor] = $this->lesson();
        $this->paidLessonOrder($session, $learner);

        $this->actingAs($tutor)->get('/money/earnings')->assertOk()->assertSee('Pending')->assertSee("R255.00", false);
        $this->actingAs($tutor)->get('/money/ops')->assertForbidden();
        // The old finance desk forwards to the operations console's exceptions tab (R-47 fix D-2400).
        $finance = $this->financeOperator();
        $this->actingAs($finance)->get('/money/ops')->assertRedirect(route('ops.payments', ['tab' => 'exceptions']));
        $this->actingAs($finance)->get(route('ops.payments', ['tab' => 'exceptions']))->assertOk()->assertSee('Exceptions and unmatched payments')->assertDontSee('/api/tutor/money/ops');
    }
}
