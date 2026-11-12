<?php

namespace App\Domain\Teach\Services;

use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutAccount;
use App\Domain\Money\Money;
use App\Domain\Money\Services\Earnings;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The tutor's money in the words the brief asks for (section 17): pending, available, the next payout run and
 * what stands in its way. Every figure comes from the money domain's ledger-backed earnings.
 */
class MoneyGlance
{
    public function __construct(private Earnings $earnings) {}

    /** @return array<string, mixed> */
    public function for(User $tutor, string $tz): array
    {
        $balances = $this->earnings->balances($tutor->id);
        $account = PayoutAccount::query()->where('user_id', $tutor->id)->first();
        $minimum = Money::of((int) config('money.payouts.minimum_minor'));
        $run = $this->nextRun($tz);

        $nextRelease = Earning::query()->where('seller_id', $tutor->id)->where('status', Earning::PENDING)
            ->whereNotNull('release_after')->orderBy('release_after')->first();
        $pendingUndelivered = Earning::query()->where('seller_id', $tutor->id)->where('status', Earning::PENDING)
            ->whereNull('release_after')->count();

        $blocker = match (true) {
            $account === null => 'Add your bank details: payouts need them.',
            $balances['available']->minor < $minimum->minor => 'Available earnings are below the '.$minimum->format().' payout minimum; they carry forward.',
            default => null,
        };

        return [
            'pending' => $balances['pending'],
            'available' => $balances['available'],
            'paid_out' => $balances['paid_out'],
            'lifetime' => $balances['lifetime_net'],
            'minimum' => $minimum,
            'next_run' => $run,
            'blocker' => $blocker,
            'has_account' => $account !== null,
            'account' => $account?->toSafeArray(),
            'next_release' => $nextRelease ? [
                'net' => Money::of($nextRelease->net_minor, $nextRelease->currency),
                'at' => $nextRelease->release_after->copy()->timezone($tz),
            ] : null,
            'awaiting_delivery' => $pendingUndelivered,
            'last_payout' => Payout::query()->where('payee_id', $tutor->id)->orderByDesc('id')->first(),
        ];
    }

    /** The next payout drafting run (the money scheduler drafts at 06:00 app time on the configured weekday). */
    public function nextRun(string $tz): CarbonImmutable
    {
        $day = max(1, min(7, (int) config('money.payouts.schedule_day', 1)));
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $candidate = $now->startOfDay()->setTime(6, 0);
        while ($candidate->dayOfWeekIso !== $day || $candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->timezone($tz);
    }
}
