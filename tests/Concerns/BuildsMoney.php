<?php

namespace Tests\Concerns;

use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Money;
use App\Domain\Money\Services\Reconciliation;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakePaystack;

/**
 * Builders for money tests. Nothing reaches the network: every Paystack call is answered by FakePaystack.
 */
trait BuildsMoney
{
    use BuildsMarketplace;

    protected FakePaystack $paystack;

    protected const SECRET = 'sk_test_money_secret';

    protected function setUpPaystack(): FakePaystack
    {
        config(['money.paystack.secret_key' => self::SECRET, 'money.paystack.allow_live' => false]);
        $this->paystack = new FakePaystack;
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->paystack->handle($request));

        return $this->paystack;
    }

    /**
     * A pending lesson between a new learner and a new approved tutor at R300 an hour for 60 minutes.
     *
     * @return array{0: TutorSession, 1: User, 2: User, 3: TutorProfile}
     */
    protected function lesson(int $hourlyRate = 300, int $minutes = 60): array
    {
        $subject = $this->makeSubject('Mathematics '.Str::random(4));
        $profile = $this->makeTutor(['hourly_rate' => $hourlyRate], $subject);
        $learner = $this->makeUser('Learner '.Str::random(4));
        $session = $this->makeSession($profile, $learner, $subject, ['duration_minutes' => $minutes, 'currency' => 'ZAR']);

        return [$session, $learner, $profile->user, $profile];
    }

    protected function financeOperator(): User
    {
        return $this->makeOperator();
    }

    /**
     * @param  list<array{type: string, id: int}>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function placeOrder(User $buyer, array $items, string $key = 'key-1', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($buyer)->withHeader('Idempotency-Key', $key)->postJson('/api/tutor/money/orders', ['items' => $items] + $extra);
    }

    protected function bookingItem(TutorSession $session): array
    {
        return ['type' => 'booking', 'id' => $session->id];
    }

    /** Create and pay an order for a lesson end to end (checkout, Paystack page, return, verify). */
    protected function paidLessonOrder(?TutorSession $session = null, ?User $learner = null): Order
    {
        if ($session === null) {
            [$session, $learner] = $this->lesson();
        }

        $uuid = $this->placeOrder($learner, [$this->bookingItem($session)], 'pay-'.$session->id)->assertCreated()->json('order.id');
        $this->actingAs($learner)->postJson("/api/tutor/money/orders/{$uuid}/pay")->assertOk()->assertJsonPath('status', 'redirect');
        $reference = $this->paystack->lastReference();
        $this->actingAs($learner)->get('/money/checkout/return?reference='.$reference)->assertRedirect();

        return Order::where('uuid', $uuid)->firstOrFail();
    }

    protected function signedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/tutor/money/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body);
    }

    protected function assertLedgerHealthy(): void
    {
        $ledger = app(Ledger::class);
        $this->assertCount(0, $ledger->unbalancedJournals(), 'Every journal balances.');
        $trial = $ledger->trialBalance(Money::defaultCurrency());
        $this->assertSame((int) $trial->sum('debit_minor'), (int) $trial->sum('credit_minor'), 'Total debits equal total credits.');
        $report = app(Reconciliation::class)->report(CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addYear());
        $this->assertSame([], $report['mismatches'], 'Reconciliation finds nothing: '.json_encode($report['mismatches']));
    }
}
