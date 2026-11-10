<?php

namespace App\Domain\Money\Http\Controllers;

use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Receipt;
use App\Domain\Money\Purchasables;
use App\Domain\Money\Services\Credits;
use App\Domain\Money\Services\Orders;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Learner-facing money API: quote, order, pay, cancel, credit balance, access check, receipts.
 */
class OrderController extends Controller
{
    public function __construct(private Orders $orders) {}

    public function quote(Request $request): JsonResponse
    {
        $data = $this->validateItems($request);

        return response()->json($this->orders->quote($request->user(), $data['items'], $data['coupon_code'] ?? null, (bool) ($data['use_credit'] ?? false)));
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('buyer_id', $request->user()->id)->with('lines', 'receipt', 'coupon')->orderByDesc('id')->limit(50)->get();

        return response()->json(['orders' => $orders->map(fn (Order $o) => $o->toApi())->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $data = $this->validateItems($request);
        $existing = Order::where('idempotency_key', hash('sha256', $request->user()->id.'|'.$key))->exists();

        $order = $this->orders->createOrder($request->user(), $data['items'], $key, $data['coupon_code'] ?? null, (bool) ($data['use_credit'] ?? false));

        return response()->json(['order' => $order->toApi()], $existing ? 200 : 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeView($request, $order);

        return response()->json(['order' => $order->toApi()]);
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        $start = $this->orders->startPayment($order, $request->user());

        return response()->json($start->toApi());
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->orders->cancel($order, $request->user())->toApi()]);
    }

    public function credits(Request $request, Credits $credits): JsonResponse
    {
        $id = $request->user()->id;

        return response()->json([
            'balance' => $credits->balance($id),
            'reserved' => $credits->reserved($id),
            'available' => $credits->available($id),
        ]);
    }

    public function entitlement(Request $request, EntitlementCheck $entitlements): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(Purchasables::TYPES)],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $source = $entitlements->source($request->user()->id, $data['type'], (int) $data['id']);

        return response()->json(['owns' => $source !== null, 'source' => $source]);
    }

    public function receipts(Request $request): JsonResponse
    {
        $receipts = Receipt::where('buyer_id', $request->user()->id)->orderByDesc('id')->limit(100)->get(['id', 'number', 'order_id', 'issued_at', 'snapshot']);

        return response()->json(['receipts' => $receipts->map(fn (Receipt $r) => [
            'id' => $r->id,
            'number' => $r->number,
            'total_minor' => $r->snapshot['total_minor'] ?? null,
            'currency' => $r->snapshot['currency'] ?? null,
            'issued_at' => $r->issued_at->toIso8601String(),
            'url' => route('money.receipts.show', $r),
        ])->all()]);
    }

    public function receipt(Request $request, Receipt $receipt): JsonResponse
    {
        abort_unless($receipt->buyer_id === $request->user()->id || Gate::allows('money.finance'), 404);

        return response()->json(['number' => $receipt->number, 'issued_at' => $receipt->issued_at->toIso8601String()] + $receipt->snapshot);
    }

    /**
     * @return array{items: list<array{type: string, id: int}>, coupon_code?: ?string, use_credit?: bool}
     */
    private function validateItems(Request $request): array
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('money.orders.max_lines', 20)],
            'items.*.type' => ['required', 'string', Rule::in(Purchasables::TYPES)],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'use_credit' => ['nullable', 'boolean'],
        ]);

        $data['items'] = array_map(fn (array $i) => ['type' => (string) $i['type'], 'id' => (int) $i['id']], array_values($data['items']));

        return $data;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?? $request->input('idempotency_key', '')));

        if ($key === '' || strlen($key) > 100) {
            throw CheckoutRefused::because('idempotency_key_required', 'Send an Idempotency-Key header (up to 100 characters) so a repeated request cannot create a second order.', 428);
        }

        return $key;
    }

    private function authorizeView(Request $request, Order $order): void
    {
        $user = $request->user();

        abort_unless($order->buyer_id === $user->id || app(PurchaseApprovals::class)->canPayFor($user, $order) || Gate::allows('money.finance'), 404);
    }
}
