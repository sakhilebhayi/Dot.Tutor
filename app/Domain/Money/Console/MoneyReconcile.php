<?php

namespace App\Domain\Money\Console;

use App\Domain\Money\Services\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MoneyReconcile extends Command
{
    protected $signature = 'money:reconcile
        {--from= : Start date (default: yesterday)}
        {--to= : End date (default: now)}
        {--paystack-csv= : A Paystack transactions export to compare (columns reference, amount)}
        {--record : Put mismatches on the finance exceptions list}';

    protected $description = 'Reconcile orders, refunds, payouts, earnings and credit against the ledger (and optionally a Paystack export)';

    public function handle(Reconciliation $reconciliation): int
    {
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from'))->startOfDay() : CarbonImmutable::yesterday();
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to'))->endOfDay() : CarbonImmutable::now();
        $record = (bool) $this->option('record');
        $report = $reconciliation->report($from, $to, $record);

        $this->line("Ledger balanced: ".($report['ledger']['balanced'] ? 'yes' : 'NO'));
        $this->line('Verified payments: '.$report['verified_payments']['count']);

        $mismatches = $report['mismatches'];

        if (($path = $this->option('paystack-csv')) !== null) {
            if (! is_readable($path)) {
                $this->error('Cannot read '.$path);

                return self::FAILURE;
            }

            $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
            $assoc = array_map(fn ($r) => array_combine($header, array_pad($r, count($header), '')), $rows);
            $compare = $reconciliation->compareSettlement($assoc, $record);
            $this->line('Paystack rows matched: '.$compare['matched']);
            $mismatches = array_merge($mismatches, $compare['mismatches']);
        }

        foreach ($mismatches as $m) {
            $this->warn(json_encode($m));
        }

        $this->line(count($mismatches).' mismatch(es).');

        return $mismatches === [] ? self::SUCCESS : self::FAILURE;
    }
}
