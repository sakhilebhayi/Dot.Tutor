<?php

namespace Tests\Feature\Money;

use App\Domain\Money\Ledger\Account;
use App\Domain\Money\Ledger\JournalEntry;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\Models\LedgerEntry;
use App\Domain\Money\Ledger\Models\LedgerJournal;
use App\Domain\Money\Ledger\UnbalancedJournal;
use App\Domain\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class LedgerAndMoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_parses_formats_and_rounds_without_floats(): void
    {
        $this->assertSame(125050, Money::fromDecimal('1250.50')->minor);
        $this->assertSame(1001, Money::fromDecimal('10.005')->minor);
        $this->assertSame(-350, Money::fromDecimal('-3.5')->minor);
        $this->assertSame("R1\u{202F}250.50", Money::of(125050)->format());
        $this->assertSame("\u{2212}R0.05", Money::of(-5)->format());
        $this->assertSame("$1\u{202F}000\u{202F}000.00", Money::of(100000000, 'USD')->format());
        $this->assertSame('1250.50', Money::of(125050)->toDecimalString());
        $this->assertSame(3750, Money::of(25000)->percentBps(1500)->minor);
        $this->assertSame(2, Money::of(15)->percentBps(1500)->minor, '2.25 rounds to 2');
        $this->assertSame(45000, Money::of(30000)->ratio(90, 60)->minor);

        $parts = Money::of(100)->allocate([1, 1, 1]);
        $this->assertSame(100, array_sum(array_map(fn (Money $m) => $m->minor, $parts)));
        $this->assertSame([34, 33, 33], array_map(fn (Money $m) => $m->minor, $parts));

        $this->expectException(InvalidArgumentException::class);
        Money::of(1, 'ZAR')->plus(Money::of(1, 'USD'));
    }

    public function test_money_refuses_text_that_is_not_a_plain_decimal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal('1,250.00');
    }

    public function test_an_unbalanced_journal_is_refused(): void
    {
        $entry = (new JournalEntry('test', 'k1', 'ZAR', 'test'))->debit(Account::GATEWAY_CLEARING, 100)->credit(Account::PLATFORM_COMMISSION, 99);

        $this->expectException(UnbalancedJournal::class);
        app(Ledger::class)->post($entry);
    }

    public function test_posting_the_same_key_twice_writes_once(): void
    {
        $ledger = app(Ledger::class);
        $make = fn () => (new JournalEntry('test', 'same', 'ZAR', 'test'))->debit(Account::GATEWAY_CLEARING, 100)->credit(Account::tutorPending(7), 100);

        $first = $ledger->post($make());
        $second = $ledger->post($make());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerJournal::count());
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame(100, $ledger->balance(Account::tutorPending(7), 'ZAR'));
    }

    public function test_ledger_rows_are_immutable_in_the_model_and_in_the_database(): void
    {
        $journal = app(Ledger::class)->post((new JournalEntry('test', 'imm', 'ZAR', 'test'))->debit(Account::GATEWAY_CLEARING, 100)->credit(Account::PLATFORM_COMMISSION, 100));

        try {
            $journal->entries()->first()->forceFill(['debit_minor' => 5])->save();
            $this->fail('The model allowed an update.');
        } catch (LogicException) {
        }

        try {
            DB::table('money_ledger_entries')->where('journal_id', $journal->id)->update(['debit_minor' => 5]);
            $this->fail('The database allowed an update.');
        } catch (QueryException) {
        }

        try {
            DB::table('money_ledger_journals')->delete();
            $this->fail('The database allowed a delete.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('money_ledger_entries')->insert(['journal_id' => $journal->id, 'account' => 'platform_bank', 'account_type' => 'platform_bank', 'debit_minor' => 5, 'credit_minor' => 5, 'currency' => 'ZAR']);
    }

    public function test_unknown_accounts_and_negative_amounts_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JournalEntry('test', 'neg', 'ZAR', 'test'))->debit(Account::GATEWAY_CLEARING, -1);
    }

    public function test_party_accounts_need_a_party(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Account::parse('tutor_payable');
    }

    /** A random walk of balanced events never leaves the ledger out of balance. */
    public function test_property_random_balanced_journals_keep_the_trial_balance_at_zero(): void
    {
        $ledger = app(Ledger::class);
        mt_srand(47);

        for ($i = 0; $i < 150; $i++) {
            $amount = mt_rand(1, 500000);
            $split = mt_rand(0, $amount);
            $tutor = mt_rand(1, 5);
            $entry = (new JournalEntry('prop', "prop-{$i}", 'ZAR', 'property'))
                ->debit(Account::GATEWAY_CLEARING, $amount)
                ->credit(Account::tutorPending($tutor), $split)
                ->credit(Account::PLATFORM_COMMISSION, $amount - $split);
            $ledger->post($entry);
        }

        $trial = $ledger->trialBalance('ZAR');
        $this->assertSame((int) $trial->sum('debit_minor'), (int) $trial->sum('credit_minor'));
        $this->assertCount(0, $ledger->unbalancedJournals());
    }
}
