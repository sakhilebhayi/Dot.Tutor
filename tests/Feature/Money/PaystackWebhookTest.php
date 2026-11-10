<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Ledger\Models\LedgerJournal;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Payment;
use App\Domain\Money\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
    }

    /** @return array{0: Order, 1: Payment} */
    private function openCheckout(): array
    {
        [$session, $learner] = $this->lesson();
        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)])->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk();

        return [Order::where('uuid', $uuid)->firstOrFail(), Payment::firstOrFail()];
    }

    private function chargeSuccess(Payment $payment, array $extra = []): array
    {
        return ['event' => 'charge.success', 'data' => array_merge([
            'id' => 555, 'reference' => $payment->reference, 'status' => 'success', 'amount' => $payment->amount_minor, 'currency' => 'ZAR',
            'customer' => ['email' => 'learner@example.com', 'phone' => '0821234567'], 'authorization' => ['last4' => '4081'], 'metadata' => ['order_id' => 1],
        ], $extra)];
    }

    public function test_a_forged_signature_is_refused_and_nothing_is_stored(): void
    {
        [$order, $payment] = $this->openCheckout();
        $body = json_encode($this->chargeSuccess($payment));

        $this->call('POST', '/api/tutor/money/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_someone_else'),
        ], $body)->assertStatus(401)->assertJsonPath('error', 'invalid_signature');

        $this->call('POST', '/api/tutor/money/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_without_a_configured_key_every_webhook_is_refused(): void
    {
        [, $payment] = $this->openCheckout();
        config(['money.paystack.secret_key' => null]);

        $this->signedWebhook($this->chargeSuccess($payment))->assertStatus(401);
    }

    public function test_a_signed_charge_success_is_verified_with_paystack_then_settles_the_order(): void
    {
        [$order, $payment] = $this->openCheckout();

        $this->signedWebhook($this->chargeSuccess($payment))->assertOk()->assertJsonPath('status', 'queued');

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, $this->paystack->calls('verify'));
        $event = WebhookEvent::firstOrFail();
        $this->assertSame('processed', $event->status);
        $this->assertArrayNotHasKey('customer', $event->payload['data']);
        $this->assertArrayNotHasKey('authorization', $event->payload['data']);
        $this->assertArrayNotHasKey('metadata', $event->payload['data']);
        $this->assertLedgerHealthy();
    }

    public function test_a_duplicate_webhook_is_acknowledged_and_processed_once(): void
    {
        [$order, $payment] = $this->openCheckout();

        $this->signedWebhook($this->chargeSuccess($payment))->assertOk()->assertJsonPath('status', 'queued');
        $this->signedWebhook($this->chargeSuccess($payment))->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, LedgerJournal::where('kind', 'order_paid')->count());
        $this->assertSame(1, $this->paystack->calls('verify'));
        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_a_webhook_body_is_not_trusted_when_paystack_says_otherwise(): void
    {
        [$order, $payment] = $this->openCheckout();
        $this->paystack->verifyOverrides[$payment->reference] = ['status' => 'failed'];

        $this->signedWebhook($this->chargeSuccess($payment))->assertOk();

        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame(0, LedgerJournal::count());
    }

    public function test_a_payment_we_never_issued_is_kept_for_review(): void
    {
        $this->paystack->verifyOverrides['someone-elses-ref'] = ['amount' => 12345];

        $this->signedWebhook(['event' => 'charge.success', 'data' => ['id' => 9, 'reference' => 'someone-elses-ref', 'status' => 'success', 'amount' => 12345, 'currency' => 'ZAR']])->assertOk();

        $issue = FinanceIssue::firstOrFail();
        $this->assertSame('unmatched_payment', $issue->kind);
        $this->assertSame(12345, $issue->amount_minor);
        $this->assertSame(0, LedgerJournal::count());
    }

    public function test_a_payment_for_an_order_already_paid_is_flagged_for_refund(): void
    {
        [$order, $first] = $this->openCheckout();
        // A second checkout page for the same order (the first expired) that was also paid.
        $first->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($order->buyer)->postJson("/api/tutor/money/orders/{$order->uuid}/pay")->assertOk();
        $second = Payment::where('id', '!=', $first->id)->firstOrFail();

        $this->signedWebhook($this->chargeSuccess($first))->assertOk();
        $this->signedWebhook($this->chargeSuccess($second, ['id' => 556]))->assertOk();

        $this->assertSame(Payment::PAID, $second->fresh()->status);
        $this->assertSame('duplicate_payment', FinanceIssue::firstOrFail()->kind);
        $this->assertSame(1, LedgerJournal::where('kind', 'order_paid')->count());
    }

    public function test_the_secret_and_body_never_reach_the_logs(): void
    {
        [, $payment] = $this->openCheckout();
        Log::spy();

        $body = json_encode($this->chargeSuccess($payment));
        $this->call('POST', '/api/tutor/money/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $flat = json_encode($context);

            return $message === 'paystack.webhook_signature_refused' && ! str_contains($flat, self::SECRET) && ! str_contains($flat, 'learner@example.com');
        });
    }
}
