<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\Receipt;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The learner's orders and receipts (D-1113), read from the money domain. Receipts open on money's own page. */
class PurchasesController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('student.purchases', [
            'orders' => Order::query()->with(['lines', 'receipt'])->where('buyer_id', $user->id)->orderByDesc('id')->limit(50)->get(),
            'receipts' => Receipt::query()->where('buyer_id', $user->id)->orderByDesc('id')->limit(100)->get(['id', 'number', 'order_id', 'issued_at', 'snapshot']),
        ]);
    }
}
