<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Models\CommissionRule;
use App\Domain\Money\Money;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Commission: a platform default and per-category overrides, versioned. A new version supersedes the
 * current one from its effective time; nothing is edited, so every order line keeps the rule it was priced
 * with. Without any rule row the platform default comes from config (money.commission.default_percent).
 */
class CommissionRules
{
    public function __construct(private MoneyAudit $audit) {}

    /**
     * @return array{bps: int, rule_id: ?int, version: ?int, category: ?string}
     */
    public function ruleFor(string $category, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        foreach ([$category, null] as $candidate) {
            $rule = CommissionRule::query()
                ->when($candidate === null, fn ($q) => $q->whereNull('category'), fn ($q) => $q->where('category', $candidate))
                ->where('effective_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $at))
                ->orderByDesc('version')
                ->first();

            if ($rule !== null) {
                return ['bps' => $rule->percent_bps, 'rule_id' => $rule->id, 'version' => $rule->version, 'category' => $rule->category];
            }
        }

        return ['bps' => self::percentToBps((float) config('money.commission.default_percent', 15)), 'rule_id' => null, 'version' => null, 'category' => null];
    }

    public function addVersion(?string $category, float $percent, string $reason, User $actor, ?CarbonInterface $effectiveFrom = null): CommissionRule
    {
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Commission must be between 0 and 100 percent.');
        }

        $effectiveFrom ??= now();

        $rule = DB::transaction(function () use ($category, $percent, $reason, $actor, $effectiveFrom) {
            $current = CommissionRule::query()
                ->when($category === null, fn ($q) => $q->whereNull('category'), fn ($q) => $q->where('category', $category))
                ->lockForUpdate()
                ->orderByDesc('version')
                ->get();

            foreach ($current->whereNull('superseded_at') as $old) {
                $old->forceFill(['superseded_at' => $effectiveFrom])->save();
            }

            return CommissionRule::create([
                'category' => $category,
                'percent_bps' => self::percentToBps($percent),
                'version' => ((int) $current->max('version')) + 1,
                'effective_from' => $effectiveFrom,
                'reason' => mb_substr($reason, 0, 255),
                'created_by' => $actor->id,
            ]);
        });

        $this->audit->record($actor, 'money.commission_rule_added', $rule, ['category' => $category, 'percent_bps' => $rule->percent_bps, 'version' => $rule->version]);

        return $rule;
    }

    /**
     * What a tutor keeps from one sale at this price: commission, other charges and net, with the rule used.
     *
     * @return array{price: Money, commission_bps: int, commission: Money, charges: list<array{label: string, amount: Money}>, charges_total: Money, net: Money, rule_id: ?int, rule_version: ?int, rule_category: ?string}
     */
    public function split(Money $base, string $category, ?CarbonInterface $at = null): array
    {
        $rule = $this->ruleFor($category, $at);
        $commission = $base->percentBps($rule['bps']);
        $charges = [];
        $gatewayBps = self::percentToBps((float) config('money.charges.pass_gateway_fee_percent', 0));
        $fixed = (int) config('money.charges.fixed_fee_minor', 0);

        if ($gatewayBps > 0) {
            $charges[] = ['label' => 'Payment processing passed on to the tutor', 'amount' => $base->percentBps($gatewayBps)];
        }

        if ($fixed > 0 && $base->isPositive()) {
            $charges[] = ['label' => 'Fixed platform fee per sale', 'amount' => Money::of($fixed, $base->currency)];
        }

        // Never take more than the sale: charges are trimmed so the net is at least zero.
        $room = max(0, $base->minor - $commission->minor);

        foreach ($charges as $i => $charge) {
            $take = min($room, $charge['amount']->minor);
            $charges[$i]['amount'] = Money::of($take, $base->currency);
            $room -= $take;
        }

        $chargesTotal = Money::of(array_sum(array_map(fn ($c) => $c['amount']->minor, $charges)), $base->currency);

        return [
            'price' => $base,
            'commission_bps' => $rule['bps'],
            'commission' => $commission,
            'charges' => array_values(array_filter($charges, fn ($c) => $c['amount']->isPositive())),
            'charges_total' => $chargesTotal,
            'net' => $base->minus($commission)->minus($chargesTotal),
            'rule_id' => $rule['rule_id'],
            'rule_version' => $rule['version'],
            'rule_category' => $rule['category'],
        ];
    }

    public static function percentToBps(float $percent): int
    {
        return (int) round($percent * 100);
    }
}
