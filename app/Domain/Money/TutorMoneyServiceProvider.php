<?php

namespace App\Domain\Money;

use App\Domain\Money\Console\MoneyDraftPayouts;
use App\Domain\Money\Console\MoneyReconcile;
use App\Domain\Money\Console\MoneySettle;
use App\Domain\Money\Contracts\BuyerAge;
use App\Domain\Money\Contracts\Checkout;
use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Models\Bundle;
use App\Domain\Money\Purchasables\BookingPurchasable;
use App\Domain\Money\Purchasables\BundlePurchasable;
use App\Domain\Money\Seams\AgeBandPurchaseApprovals;
use App\Domain\Money\Seams\LogMoneyAudit;
use App\Domain\Money\Seams\UnknownBuyerAge;
use App\Domain\Money\Services\Entitlements;
use App\Domain\Money\Services\Orders;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 money domain. Seams are bound with bindIf so the wave-2 integration provider (registered last) can bind
 * the real safety adapters (age band, guardian approvals, audit). Gates, rate limiters, commands and the
 * schedule live here, never in route files.
 */
class TutorMoneyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Purchasables::class);
        $this->app->bindIf(Checkout::class, Orders::class);
        $this->app->bindIf(EntitlementCheck::class, Entitlements::class);
        $this->app->bindIf(PurchaseApprovals::class, AgeBandPurchaseApprovals::class);
        $this->app->bindIf(BuyerAge::class, UnknownBuyerAge::class);
        $this->app->bindIf(MoneyAudit::class, LogMoneyAudit::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'money');

        $registry = $this->app->make(Purchasables::class);
        $registry->registerIfMissing('booking', function (int $id) {
            $session = TutorSession::with('tutorProfile.user', 'subject')->find($id);

            return $session === null ? null : new BookingPurchasable($session);
        });
        $registry->registerIfMissing('bundle', function (int $id) {
            $bundle = Bundle::find($id);

            return $bundle === null ? null : new BundlePurchasable($bundle);
        });

        // Finance operators: platform operators, narrowed to MONEY_FINANCE_OPERATOR_EMAILS when it is set,
        // until the ops domain adds the finance role.
        Gate::define('money.finance', function (User $user): bool {
            if (! $user->is_platform_operator) {
                return false;
            }

            $allowed = array_map('strtolower', (array) config('money.finance_operators', []));

            return $allowed === [] || in_array(strtolower((string) $user->email), $allowed, true);
        });

        RateLimiter::for('money-checkout', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('money-webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([MoneySettle::class, MoneyReconcile::class, MoneyDraftPayouts::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('money:settle')->everyFiveMinutes()->withoutOverlapping(10);
            $schedule->command('money:reconcile --record')->dailyAt('02:30')->withoutOverlapping(60);
            $schedule->command('money:draft-payouts')->dailyAt('06:00')->withoutOverlapping(60);
        });
    }
}
