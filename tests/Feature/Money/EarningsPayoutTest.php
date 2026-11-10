<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\Models\LedgerJournal;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutAccount;
use App\Domain\Money\Services\Earnings;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMoney;
use Tests\TestCase;

class EarningsPayoutTest extends TestCase
{
    use BuildsMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaystack();
        config(['money.commission.default_percent' => 15, 'money.payouts.minimum_minor' => 10000, 'money.earnings.hold_hours_after_lesson' => 24]);
    }

    private function bank(User $tutor, string $number = '62001234567'): void
    {
        $this->actingAs($tutor)->putJson('/api/tutor/money/tutor/payout-account', [
            'holder_name' => 'T Tutor', 'account_number' => $number, 'bank_name' => 'First National Bank', 'bank_code' => '250655',
        ])->assertOk()->assertJsonPath('account.account_ending', substr($number, -4))->assertJsonMissingPath('account.account_number');
    }

    /** @return array{0: User, 1: TutorSession} */
    private function completedPaidLesson(): array
    {
        [$session, $learner, $tutor] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $session->forceFill(['status' => 'completed'])->save();

        return [$tutor, $session];
    }

    public function test_earnings_stay_pending_until_the_lesson_is_completed_and_the_hold_ends(): void
    {
        [$session, $learner, $tutor] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $earnings = app(Earnings::class);

        $earnings->releaseDue(now()->addDays(5));
        $this->assertSame(Earning::PENDING, Earning::firstOrFail()->status, 'Not completed: still pending.');

        $session->forceFill(['status' => 'completed'])->save();
        $earnings->releaseDue(now());
        $this->assertSame(Earning::PENDING, Earning::firstOrFail()->status, 'Within the complaint hold.');

        $this->travel(25)->hours();
        $earnings->releaseDue();
        $this->assertSame(Earning::AVAILABLE, Earning::firstOrFail()->status);
        $this->assertSame(25500, app(Ledger::class)->balance(Account::tutorPayable($tutor->id), 'ZAR'));
        $this->assertSame(0, app(Ledger::class)->balance(Account::tutorPending($tutor->id), 'ZAR'));

        $this->actingAs($tutor)->getJson('/api/tutor/money/tutor/earnings')->assertOk()
            ->assertJsonPath('balances.available.amount_minor', 25500)
            ->assertJsonPath('balances.pending.amount_minor', 0)
            ->assertJsonPath('earnings.0.commission.amount_minor', 4500);
        $this->assertLedgerHealthy();
    }

    public function test_a_no_show_goes_to_finance_review_instead_of_paying_out(): void
    {
        [$session, $learner] = $this->lesson();
        $this->paidLessonOrder($session, $learner);
        $session->forceFill(['status' => 'no_show'])->save();

        app(Earnings::class)->releaseDue(now()->addDays(3));

        $this->assertSame(Earning::PENDING, Earning::firstOrFail()->status);
        $this->assertSame('delivery_needs_review', FinanceIssue::firstOrFail()->kind);
    }

    public function test_bank_details_are_encrypted_at_rest(): void
    {
        [$tutor] = $this->completedPaidLesson();
        $this->bank($tutor);

        $raw = DB::table('money_payout_accounts')->first();
        $this->assertStringNotContainsString('62001234567', (string) $raw->account_number);
        $this->assertStringNotContainsString('T Tutor', (string) $raw->holder_name);
        $this->assertSame('62001234567', PayoutAccount::firstOrFail()->account_number);
    }

    public function test_one_account_registered_by_two_tutors_is_flagged(): void
    {
        [$tutor] = $this->completedPaidLesson();
        [, , $otherTutor] = $this->lesson();
        $this->bank($tutor);
        $this->bank($otherTutor);

        $this->assertSame('shared_bank_account', FinanceIssue::firstOrFail()->kind);
    }

    public function test_the_manual_payout_batch_from_draft_to_paid(): void
    {
        [$tutor] = $this->completedPaidLesson();
        $this->bank($tutor);
        [$noBankTutor] = $this->completedPaidLesson();
        $this->travel(2)->days();
        app(Earnings::class)->releaseDue();
        $operator = $this->financeOperator();

        $batchId = $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()
            ->assertJsonPath('batch.payout_count', 1)
            ->assertJsonPath('batch.carried_forward.0.reason', 'no_bank_account')
            ->json('batch.id');

        $csv = $this->actingAs($operator)->get("/api/tutor/money/ops/payout-batches/{$batchId}/export")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
        $this->assertStringContainsString('62001234567', $csv);
        $this->assertStringContainsString('255.00', $csv);

        $payout = Payout::firstOrFail();
        $this->actingAs($operator)->postJson("/api/tutor/money/ops/payouts/{$payout->id}/paid")->assertOk()->assertJsonPath('payout.status', 'paid');
        $this->actingAs($operator)->postJson("/api/tutor/money/ops/payouts/{$payout->id}/paid")->assertOk();

        $this->assertSame(1, LedgerJournal::where('kind', 'payout')->count());
        $this->assertSame(Earning::PAID, Earning::where('seller_id', $tutor->id)->firstOrFail()->status);
        $this->assertSame(0, app(Ledger::class)->balance(Account::tutorPayable($tutor->id), 'ZAR'));

        $this->actingAs($tutor)->getJson("/api/tutor/money/tutor/payouts/{$payout->id}")->assertOk()
            ->assertJsonPath('amount.amount_minor', 25500)->assertJsonPath('earnings.0.net.amount_minor', 25500);
        $this->actingAs($noBankTutor)->getJson("/api/tutor/money/tutor/payouts/{$payout->id}")->assertNotFound();
        $this->assertLedgerHealthy();
    }

    public function test_below_the_minimum_is_carried_forward(): void
    {
        config(['money.payouts.minimum_minor' => 50000]);
        [$tutor] = $this->completedPaidLesson();
        $this->bank($tutor);
        $this->travel(2)->days();
        app(Earnings::class)->releaseDue();

        $this->actingAs($this->financeOperator())->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()
            ->assertJsonPath('batch.payout_count', 0)->assertJsonPath('batch.carried_forward.0.reason', 'below_minimum');
    }

    public function test_a_failed_payout_returns_its_earnings_to_the_next_batch(): void
    {
        [$tutor] = $this->completedPaidLesson();
        $this->bank($tutor);
        $this->travel(2)->days();
        app(Earnings::class)->releaseDue();
        $operator = $this->financeOperator();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated();
        $payout = Payout::firstOrFail();
        $this->actingAs($operator)->postJson("/api/tutor/money/ops/payouts/{$payout->id}/paid")->assertOk();

        $this->actingAs($operator)->postJson("/api/tutor/money/ops/payouts/{$payout->id}/failed", ['reason' => 'Bank returned the payment'])->assertOk()->assertJsonPath('payout.status', 'failed');

        $earning = Earning::firstOrFail();
        $this->assertSame([Earning::AVAILABLE, null], [$earning->status, $earning->payout_id]);
        $this->assertSame(25500, app(Ledger::class)->balance(Account::tutorPayable($tutor->id), 'ZAR'));
        $this->assertLedgerHealthy();

        $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated()->assertJsonPath('batch.payout_count', 1);
    }

    public function test_paystack_transfers_are_off_by_default_and_work_when_switched_on(): void
    {
        [$tutor] = $this->completedPaidLesson();
        $this->bank($tutor);
        $this->travel(2)->days();
        app(Earnings::class)->releaseDue();
        $operator = $this->financeOperator();
        $this->actingAs($operator)->postJson('/api/tutor/money/ops/payout-batches')->assertCreated();
        $payout = Payout::firstOrFail();

        $this->actingAs($operator)->postJson("/api/tutor/money/ops/payouts/{$payout->id}/transfer")->assertStatus(409)->assertJsonPath('error', 'transfers_off');
        $this->assertSame(0, $this->paystack->calls('transfer'));

        config(['money.payouts.paystack_transfers' => true]);
        $this->actingAs($operator)->postJson("/api/tutor/money/ops/payouts/{$payout->id}/transfer")->assertOk()->assertJsonPath('payout.status', 'processing');
        $this->signedWebhook(['event' => 'transfer.success', 'data' => ['reference' => $payout->reference, 'status' => 'success', 'amount' => $payout->amount_minor]])->assertOk();

        $this->assertSame(Payout::PAID, $payout->fresh()->status);
        $this->assertSame('paystack_transfer', $payout->fresh()->method);
        $this->assertSame(1, $this->paystack->calls('transfer_verify'));
        $this->assertLedgerHealthy();
    }

    public function test_tutors_see_only_their_own_money_and_ops_is_finance_only(): void
    {
        [$tutor] = $this->completedPaidLesson();
        [, , $other] = $this->lesson();

        $this->actingAs($other)->getJson('/api/tutor/money/tutor/earnings')->assertOk()->assertJsonCount(0, 'earnings');
        $this->actingAs($tutor)->getJson('/api/tutor/money/ops/orders')->assertForbidden();
        $this->actingAs($tutor)->postJson('/api/tutor/money/ops/payout-batches')->assertForbidden();
        $batchId = $this->actingAs($this->financeOperator())->postJson('/api/tutor/money/ops/payout-batches')->json('batch.id');
        $this->actingAs($tutor)->get("/api/tutor/money/ops/payout-batches/{$batchId}/export")->assertForbidden();
    }
}
