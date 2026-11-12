<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutAccount;
use App\Domain\Money\Services\Earnings;
use App\Domain\Money\Services\Payouts;
use App\Domain\Teach\Services\Insights;
use App\Domain\Teach\Services\MoneyGlance;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tutor's money (brief section 17): every earning with its price, commission, other charges and net; pending
 * against available with the date each frees up; payouts and their statements; bank details; an income report;
 * and business insights. Every figure is read from the money domain's ledger-backed tables for this tutor only.
 */
class MoneyController extends Controller
{
    public function earnings(Request $request, Earnings $earnings, MoneyGlance $glance): View
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);
        [$from, $to] = $this->period($request, $tz);
        $user = $request->user();

        return view('teach.earnings', [
            'profile' => $profile,
            'tz' => $tz,
            'from' => $from,
            'to' => $to,
            'glance' => $glance->for($user, $tz),
            'statement' => $earnings->statement($user->id, $from->utc(), $to->utc()),
            'rows' => Earning::query()->with('orderLine')->where('seller_id', $user->id)
                ->whereBetween('created_at', [$from->utc(), $to->utc()])->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
        ]);
    }

    /** Income report as CSV for the period: one row per earning, totals at the end, formula-neutral cells. */
    public function export(Request $request, Earnings $earnings): StreamedResponse
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);
        [$from, $to] = $this->period($request, $tz);
        $statement = $earnings->statement($request->user()->id, $from->utc(), $to->utc());
        $name = 'dot-tutor-income-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($statement, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Item', 'Kind', 'Price', 'Commission', 'Other charges', 'Net', 'Currency', 'Status', 'Available from'], escape: '');
            foreach ($statement['earnings'] as $e) {
                fputcsv($out, [
                    CarbonImmutable::parse($e['created_at'])->timezone($tz)->format('Y-m-d H:i'),
                    self::cell((string) ($e['title'] ?? '')),
                    $e['kind'],
                    $e['gross']->toDecimalString(),
                    $e['commission']->toDecimalString(),
                    $e['other_charges']->toDecimalString(),
                    $e['net']->toDecimalString(),
                    $e['net']->currency,
                    $e['status'],
                    $e['available_from'] ? CarbonImmutable::parse($e['available_from'])->timezone($tz)->format('Y-m-d H:i') : '',
                ], escape: '');
            }
            $t = $statement['totals'];
            fputcsv($out, [], escape: '');
            fputcsv($out, ['Totals', '', '', $t['gross']->toDecimalString(), $t['commission']->toDecimalString(), $t['other_charges']->toDecimalString(), $t['net']->toDecimalString(), $t['net']->currency, 'Refunded: '.$t['refunded']->toDecimalString(), ''], escape: '');
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function payouts(Request $request, MoneyGlance $glance): View
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);
        $user = $request->user();

        return view('teach.payouts', [
            'profile' => $profile,
            'tz' => $tz,
            'glance' => $glance->for($user, $tz),
            'payouts' => Payout::query()->where('payee_id', $user->id)->orderByDesc('id')->paginate(25),
            'account' => PayoutAccount::query()->where('user_id', $user->id)->first(),
            'transfers' => (bool) config('money.payouts.paystack_transfers'),
        ]);
    }

    public function payout(Request $request, Payout $payout, Payouts $payouts): View
    {
        $profile = TutorContext::profile($request);
        abort_unless($payout->payee_id === $request->user()->id, 404);

        return view('teach.payout', [
            'profile' => $profile,
            'tz' => TutorContext::tz($profile),
            'payout' => $payout,
            'statement' => $payouts->statement($payout),
        ]);
    }

    public function account(Request $request, Payouts $payouts): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        if (! $profile->isApproved()) {
            return back()->with('error', 'Bank details open once your profile is approved.');
        }
        $data = $request->validate([
            'holder_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'regex:/^[0-9 ]{6,20}$/'],
            'bank_name' => ['required', 'string', 'max:80'],
            'bank_code' => ['required', 'string', 'regex:/^[0-9A-Za-z]{3,20}$/'],
            'account_type' => ['nullable', Rule::in(['personal', 'business'])],
        ], [
            'account_number.regex' => 'Use digits only (spaces are fine), 6 to 20 of them.',
            'bank_code.regex' => 'The branch code is 3 to 20 letters or digits.',
        ]);
        $payouts->saveAccount($request->user(), $data);

        return redirect()->route('teach.payouts')->with('success', 'Bank details saved. Only the last four digits are shown again.');
    }

    public function insights(Request $request, Insights $insights): View
    {
        $profile = TutorContext::profile($request);
        $tz = TutorContext::tz($profile);

        return view('teach.insights', ['profile' => $profile, 'tz' => $tz, 'data' => $insights->for($profile, $tz)]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the period in the tutor's time zone (default: this month) */
    private function period(Request $request, string $tz): array
    {
        $parse = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('Y-m-d', $v, $tz) : null;
        $from = $parse($request->query('from'))?->startOfDay() ?? CarbonImmutable::now($tz)->startOfMonth();
        $to = $parse($request->query('to'))?->endOfDay() ?? CarbonImmutable::now($tz)->endOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [$from, $to];
    }

    /** Neutralise spreadsheet formulas in a free-text cell (a title starting with = + - @). */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
