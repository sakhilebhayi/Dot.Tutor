<?php

namespace App\Domain\Money\Http\Controllers;

use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\Bundle;
use App\Domain\Money\Models\BundleItem;
use App\Domain\Money\Models\Coupon;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Payout;
use App\Domain\Money\Models\PayoutAccount;
use App\Domain\Money\Money;
use App\Domain\Money\Purchasables;
use App\Domain\Money\Services\CommissionRules;
use App\Domain\Money\Services\Coupons;
use App\Domain\Money\Services\Earnings;
use App\Domain\Money\Services\Payouts;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The tutor's money: what a price leaves them before publishing, earnings (pending, available, paid), statements,
 * payouts, bank account, their own discount codes and bundles.
 */
class TutorMoneyController extends Controller
{
    /**
     * What the tutor keeps from a price: commission (with the rule version), other charges, net. Used before
     * an offering, course or resource is published.
     */
    public function preview(Request $request, CommissionRules $rules): JsonResponse
    {
        $data = $request->validate([
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $split = $rules->split(Money::of((int) $data['price_minor']), $data['category'] ?? 'lesson');

        return response()->json([
            'price' => $split['price'],
            'commission' => [
                'percent' => $split['commission_bps'] / 100,
                'amount' => $split['commission'],
                'rule_version' => $split['rule_version'],
                'rule_category' => $split['rule_category'],
            ],
            'other_charges' => $split['charges'],
            'other_charges_total' => $split['charges_total'],
            'net' => $split['net'],
            'notes' => [
                'Paystack processing fees are '.((float) config('money.charges.pass_gateway_fee_percent', 0) > 0 ? 'passed on as shown above.' : 'paid by the platform.'),
                'Discount codes you fund lower the price commission is taken from; platform-funded codes and credits do not change what you earn.',
            ],
        ]);
    }

    public function earnings(Request $request, Earnings $earnings): JsonResponse
    {
        $id = $request->user()->id;
        $recent = Earning::with('orderLine')->where('seller_id', $id)->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'balances' => $earnings->balances($id),
            'earnings' => $recent->map(fn (Earning $e) => Earnings::present($e))->all(),
        ]);
    }

    public function statement(Request $request, Earnings $earnings): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json($earnings->statement($request->user()->id, CarbonImmutable::parse($data['from'])->startOfDay(), CarbonImmutable::parse($data['to'])->endOfDay()));
    }

    public function payouts(Request $request): JsonResponse
    {
        $payouts = Payout::where('payee_id', $request->user()->id)->orderByDesc('id')->limit(100)->get();

        return response()->json(['payouts' => $payouts->map(fn (Payout $p) => [
            'id' => $p->id,
            'reference' => $p->reference,
            'status' => $p->status,
            'amount' => Money::of($p->amount_minor, $p->currency),
            'method' => $p->method,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ])->all()]);
    }

    public function payout(Request $request, Payout $payout, Payouts $payouts): JsonResponse
    {
        abort_unless($payout->payee_id === $request->user()->id, 404);

        return response()->json($payouts->statement($payout));
    }

    public function account(Request $request): JsonResponse
    {
        $account = PayoutAccount::where('user_id', $request->user()->id)->first();

        return response()->json(['account' => $account?->toSafeArray()]);
    }

    public function saveAccount(Request $request, Payouts $payouts): JsonResponse
    {
        $this->requireTutor($request->user());

        $data = $request->validate([
            'holder_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'regex:/^[0-9 ]{6,20}$/'],
            'bank_name' => ['required', 'string', 'max:80'],
            'bank_code' => ['required', 'string', 'regex:/^[0-9A-Za-z]{3,20}$/'],
            'account_type' => ['nullable', Rule::in(['personal', 'business'])],
        ]);

        return response()->json(['account' => $payouts->saveAccount($request->user(), $data)->toSafeArray()]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $coupons = Coupon::where('owner_id', $request->user()->id)->orderByDesc('id')->get();

        return response()->json(['coupons' => $coupons->map(fn (Coupon $c) => $c->toApi())->all()]);
    }

    public function storeCoupon(Request $request, Coupons $coupons): JsonResponse
    {
        $this->requireTutor($request->user());

        return response()->json(['coupon' => $coupons->create($request->user(), Coupon::FUNDED_BY_TUTOR, self::validateCoupon($request))->toApi()], 201);
    }

    public function deactivateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        abort_unless($coupon->owner_id === $request->user()->id, 404);
        $coupon->forceFill(['is_active' => false])->save();

        return response()->json(['coupon' => $coupon->toApi()]);
    }

    public function bundles(Request $request): JsonResponse
    {
        $bundles = Bundle::with('items')->where('seller_id', $request->user()->id)->orderByDesc('id')->get();

        return response()->json(['bundles' => $bundles->map(fn (Bundle $b) => [
            'id' => $b->id,
            'title' => $b->title,
            'price' => Money::of($b->price_minor, $b->currency),
            'is_active' => $b->is_active,
            'items' => $b->items->map(fn (BundleItem $i) => ['type' => $i->purchasable_type, 'id' => $i->purchasable_id])->all(),
        ])->all()]);
    }

    /**
     * A bundle holds only the tutor's own digital items (not lessons: a lesson is booked for a time).
     */
    public function storeBundle(Request $request, Purchasables $purchasables): JsonResponse
    {
        $user = $request->user();
        $this->requireTutor($user);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items' => ['required', 'array', 'min:2', 'max:20'],
            'items.*.type' => ['required', Rule::in(['offering', 'package', 'course', 'resource', 'room'])],
            'items.*.id' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($data['items'] as $item) {
            $purchasable = $purchasables->has($item['type']) ? $purchasables->resolve($item['type'], (int) $item['id']) : null;

            if ($purchasable === null || $purchasable->sellerId() !== $user->id) {
                throw CheckoutRefused::because('item_not_yours', 'A bundle can only hold your own items that exist.', 422);
            }
        }

        $bundle = DB::transaction(function () use ($user, $data) {
            $bundle = Bundle::create(['seller_id' => $user->id, 'title' => $data['title'], 'price_minor' => (int) $data['price_minor'], 'currency' => Money::defaultCurrency(), 'is_active' => true]);

            foreach (collect($data['items'])->unique(fn ($i) => $i['type'].':'.$i['id']) as $item) {
                BundleItem::create(['bundle_id' => $bundle->id, 'purchasable_type' => $item['type'], 'purchasable_id' => (int) $item['id']]);
            }

            return $bundle;
        });

        return response()->json(['bundle' => ['id' => $bundle->id, 'title' => $bundle->title, 'price' => Money::of($bundle->price_minor, $bundle->currency)]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateCoupon(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'percent' => ['required_if:kind,percent', 'nullable', 'numeric', 'min:0.01', 'max:100'],
            'amount_minor' => ['required_if:kind,fixed', 'nullable', 'integer', 'min:1'],
            'applies_to_type' => ['nullable', Rule::in(Purchasables::TYPES)],
            'min_order_minor' => ['nullable', 'integer', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    private function requireTutor(User $user): void
    {
        if (! $user->tutorProfile?->isApproved()) {
            throw CheckoutRefused::because('not_a_tutor', 'Only an approved tutor can do this.', 403);
        }
    }
}
