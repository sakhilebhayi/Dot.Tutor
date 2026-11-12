<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\Purchasable;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\PostingRules;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Money;
use App\Domain\Money\Purchasables;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tutor earnings: pending until the lesson is delivered (plus a complaint hold) or the refund window ends,
 * then available for the next payout. A line's sale and any refund reversals are released together; a line
 * refunded in full while pending is cancelled with no money moving.
 */
class Earnings
{
    public function __construct(
        private Ledger $ledger,
        private PostingRules $rules,
        private Purchasables $purchasables,
        private FinanceIssues $issues,
    ) {}

    /**
     * Release every pending sale that is due. Returns how many lines were released or cancelled.
     */
    public function releaseDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;

        Earning::where('kind', Earning::KIND_SALE)->where('status', Earning::PENDING)->orderBy('id')
            ->chunkById(200, function ($sales) use ($now, &$count) {
                foreach ($sales as $sale) {
                    if ($this->releaseIfDue($sale, $now)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function releaseIfDue(Earning $sale, CarbonInterface $now): bool
    {
        $line = OrderLine::find($sale->order_line_id);

        if ($line === null) {
            return false;
        }

        if ($sale->release_rule === Purchasable::RELEASE_ON_DELIVERY && $sale->release_after === null) {
            $purchasable = $this->purchasables->resolve($line->purchasable_type, $line->purchasable_id);
            $state = $purchasable?->deliveryState() ?? Purchasable::DELIVERY_NEEDS_REVIEW;

            if ($state === Purchasable::DELIVERED) {
                $deliveredAt = $purchasable->deliveredAt() ?? $now;
                $sale->forceFill(['release_after' => $deliveredAt->copy()->addHours(max(0, (int) config('money.earnings.hold_hours_after_lesson', 24)))])->save();
            } elseif ($state !== Purchasable::DELIVERY_PENDING) {
                if ($line->refundableMinor() === 0 && $line->cash_minor > 0) {
                    return $this->cancelLine($line);
                }

                $this->issues->raise($state === Purchasable::DELIVERY_CANCELLED ? 'paid_item_cancelled' : 'delivery_needs_review', "delivery:{$line->id}:{$state}",
                    $state === Purchasable::DELIVERY_CANCELLED
                        ? "A paid {$line->purchasable_type} was cancelled: refund the learner or release the tutor's earning"
                        : "A paid {$line->purchasable_type} needs review before the tutor is paid ({$line->title})",
                    ['subject_type' => 'order_line', 'subject_id' => $line->id, 'amount_minor' => $line->cash_minor, 'currency' => $sale->currency]);

                return false;
            }
        }

        if ($sale->release_after === null || $sale->release_after->isAfter($now)) {
            return false;
        }

        // The hold exists for complaints: a lesson disputed or reported as a no-show during it is no longer
        // delivered, so its earning waits for finance instead of joining the next payout (R-47 security S-10).
        if ($sale->release_rule === Purchasable::RELEASE_ON_DELIVERY) {
            $state = $this->purchasables->resolve($line->purchasable_type, $line->purchasable_id)?->deliveryState()
                ?? Purchasable::DELIVERY_NEEDS_REVIEW;

            if ($state !== Purchasable::DELIVERED) {
                $this->issues->raise('delivery_needs_review', "delivery:{$line->id}:{$state}",
                    "A paid {$line->purchasable_type} needs review before the tutor is paid ({$line->title})",
                    ['subject_type' => 'order_line', 'subject_id' => $line->id, 'amount_minor' => $line->cash_minor, 'currency' => $sale->currency]);

                return false;
            }
        }

        return $this->releaseLine($line);
    }

    /**
     * Release a line's pending sale and reversals now (also used by finance after reviewing a no-show).
     */
    public function releaseLine(OrderLine $line): bool
    {
        return DB::transaction(function () use ($line) {
            $pending = Earning::where('order_line_id', $line->id)->where('status', Earning::PENDING)->lockForUpdate()->get();

            if ($pending->isEmpty()) {
                return false;
            }

            if ((int) $pending->sum('net_minor') === 0 && $pending->contains('kind', Earning::KIND_REVERSAL)) {
                return $this->cancelLine($line);
            }

            foreach ($pending as $earning) {
                $earning->forceFill(['status' => Earning::AVAILABLE, 'available_at' => now()])->save();

                if (($journal = $this->rules->earningReleased($earning)) !== null) {
                    $this->ledger->post($journal);
                }
            }

            return true;
        });
    }

    private function cancelLine(OrderLine $line): bool
    {
        // Sale and reversal cancel out on the pending account already: nothing to post.
        return Earning::where('order_line_id', $line->id)->where('status', Earning::PENDING)
            ->update(['status' => Earning::CANCELLED, 'updated_at' => now()]) > 0;
    }

    /**
     * What a tutor sees: pending, available, paid out and lifetime net, in minor units.
     *
     * @return array<string, Money>
     */
    public function balances(int $sellerId, ?string $currency = null): array
    {
        $currency ??= Money::defaultCurrency();
        $sums = Earning::where('seller_id', $sellerId)->where('currency', $currency)
            ->selectRaw('status, SUM(net_minor) as total')->groupBy('status')->pluck('total', 'status');

        $pending = (int) ($sums[Earning::PENDING] ?? 0);
        $available = (int) ($sums[Earning::AVAILABLE] ?? 0);
        $paid = (int) ($sums[Earning::PAID] ?? 0);

        return [
            'pending' => Money::of($pending, $currency),
            'available' => Money::of($available, $currency),
            'paid_out' => Money::of($paid, $currency),
            'lifetime_net' => Money::of($pending + $available + $paid, $currency),
        ];
    }

    /**
     * Earnings in a period with totals: gross, commission, other charges, net.
     *
     * @return array<string, mixed>
     */
    public function statement(int $sellerId, CarbonInterface $from, CarbonInterface $to): array
    {
        $currency = Money::defaultCurrency();
        $rows = Earning::with('orderLine')->where('seller_id', $sellerId)->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', Earning::CANCELLED)->orderBy('created_at')->orderBy('id')->get();

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'totals' => [
                'gross' => Money::of((int) $rows->where('kind', Earning::KIND_SALE)->sum('gross_minor'), $currency),
                'commission' => Money::of((int) $rows->where('kind', Earning::KIND_SALE)->sum('commission_minor'), $currency),
                'other_charges' => Money::of((int) $rows->where('kind', Earning::KIND_SALE)->sum('charges_minor'), $currency),
                'refunded' => Money::of(-(int) $rows->where('kind', Earning::KIND_REVERSAL)->sum('net_minor'), $currency),
                'net' => Money::of((int) $rows->sum('net_minor'), $currency),
            ],
            'earnings' => $rows->map(fn (Earning $e) => self::present($e))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Earning $earning): array
    {
        return [
            'id' => $earning->id,
            'kind' => $earning->kind,
            'title' => $earning->orderLine?->title,
            'gross' => Money::of($earning->gross_minor, $earning->currency),
            'commission' => Money::of($earning->commission_minor, $earning->currency),
            'other_charges' => Money::of($earning->charges_minor, $earning->currency),
            'net' => Money::of($earning->net_minor, $earning->currency),
            'status' => $earning->status,
            'release_rule' => $earning->release_rule,
            'available_from' => $earning->release_after?->toIso8601String(),
            'available_at' => $earning->available_at?->toIso8601String(),
            'payout_id' => $earning->payout_id,
            'created_at' => $earning->created_at?->toIso8601String(),
        ];
    }
}
