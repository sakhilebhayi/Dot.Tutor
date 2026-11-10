<?php

use App\Domain\Money\Http\Controllers\FinanceOpsController;
use App\Domain\Money\Http\Controllers\MoneyPagesController;
use App\Domain\Money\Http\Controllers\OrderController;
use App\Domain\Money\Http\Controllers\PaystackWebhookController;
use App\Domain\Money\Http\Controllers\TutorMoneyController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| R-47 money routes (docs/R47_MONEY.md section 8). JSON under /api/tutor/money (errors render as JSON there),
| minimal pages under /money. Rate limiters and the money.finance gate are defined in TutorMoneyServiceProvider.
*/

// Paystack webhooks: no session, no CSRF (Paystack cannot send one); the HMAC signature is the authentication.
Route::post('/api/tutor/money/webhooks/paystack', PaystackWebhookController::class)
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->middleware('throttle:money-webhook')
    ->name('money.webhooks.paystack');

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::prefix('api/tutor/money')->name('money.api.')->group(function () {
        // Learners.
        Route::post('/quote', [OrderController::class, 'quote'])->middleware('throttle:money-checkout')->name('quote');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:money-checkout')->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/pay', [OrderController::class, 'pay'])->middleware('throttle:money-checkout')->name('orders.pay');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::get('/credits', [OrderController::class, 'credits'])->name('credits');
        Route::get('/entitlements/check', [OrderController::class, 'entitlement'])->name('entitlements.check');
        Route::get('/receipts', [OrderController::class, 'receipts'])->name('receipts.index');
        Route::get('/receipts/{receipt}', [OrderController::class, 'receipt'])->name('receipts.show');

        // Tutors.
        Route::post('/commission/preview', [TutorMoneyController::class, 'preview'])->name('commission.preview');
        Route::get('/tutor/earnings', [TutorMoneyController::class, 'earnings'])->name('tutor.earnings');
        Route::get('/tutor/statement', [TutorMoneyController::class, 'statement'])->name('tutor.statement');
        Route::get('/tutor/payouts', [TutorMoneyController::class, 'payouts'])->name('tutor.payouts');
        Route::get('/tutor/payouts/{payout}', [TutorMoneyController::class, 'payout'])->name('tutor.payouts.show');
        Route::get('/tutor/payout-account', [TutorMoneyController::class, 'account'])->name('tutor.account');
        Route::put('/tutor/payout-account', [TutorMoneyController::class, 'saveAccount'])->name('tutor.account.save');
        Route::get('/tutor/coupons', [TutorMoneyController::class, 'coupons'])->name('tutor.coupons');
        Route::post('/tutor/coupons', [TutorMoneyController::class, 'storeCoupon'])->name('tutor.coupons.store');
        Route::post('/tutor/coupons/{coupon}/deactivate', [TutorMoneyController::class, 'deactivateCoupon'])->name('tutor.coupons.deactivate');
        Route::get('/tutor/bundles', [TutorMoneyController::class, 'bundles'])->name('tutor.bundles');
        Route::post('/tutor/bundles', [TutorMoneyController::class, 'storeBundle'])->name('tutor.bundles.store');

        // Finance operations.
        Route::middleware('can:money.finance')->prefix('ops')->name('ops.')->group(function () {
            Route::get('/orders', [FinanceOpsController::class, 'orders'])->name('orders');
            Route::get('/orders/{order}', [FinanceOpsController::class, 'order'])->name('orders.show');
            Route::post('/orders/{order}/lines/{line}/refunds', [FinanceOpsController::class, 'refund'])->name('refunds.store');
            Route::get('/refunds', [FinanceOpsController::class, 'refunds'])->name('refunds');
            Route::post('/orders/{order}/lines/{line}/release', [FinanceOpsController::class, 'releaseLine'])->name('lines.release');
            Route::get('/finance-issues', [FinanceOpsController::class, 'issues'])->name('issues');
            Route::post('/finance-issues/{issue}/resolve', [FinanceOpsController::class, 'resolveIssue'])->name('issues.resolve');
            Route::post('/credits', [FinanceOpsController::class, 'grantCredit'])->name('credits.grant');
            Route::post('/credits/revoke', [FinanceOpsController::class, 'revokeCredit'])->name('credits.revoke');
            Route::get('/commission-rules', [FinanceOpsController::class, 'commissionRules'])->name('commission.index');
            Route::post('/commission-rules', [FinanceOpsController::class, 'storeCommissionRule'])->name('commission.store');
            Route::post('/coupons', [FinanceOpsController::class, 'storeCoupon'])->name('coupons.store');
            Route::get('/payout-batches', [FinanceOpsController::class, 'batches'])->name('batches');
            Route::post('/payout-batches', [FinanceOpsController::class, 'draftBatch'])->name('batches.store');
            Route::get('/payout-batches/{batch}', [FinanceOpsController::class, 'batch'])->name('batches.show');
            Route::get('/payout-batches/{batch}/export', [FinanceOpsController::class, 'exportBatch'])->name('batches.export');
            Route::post('/payouts/{payout}/paid', [FinanceOpsController::class, 'payoutPaid'])->name('payouts.paid');
            Route::post('/payouts/{payout}/failed', [FinanceOpsController::class, 'payoutFailed'])->name('payouts.failed');
            Route::post('/payouts/{payout}/transfer', [FinanceOpsController::class, 'payoutTransfer'])->name('payouts.transfer');
            Route::get('/disputes', [FinanceOpsController::class, 'disputes'])->name('disputes');
            Route::post('/disputes', [FinanceOpsController::class, 'storeDispute'])->name('disputes.store');
            Route::post('/disputes/{dispute}', [FinanceOpsController::class, 'disputeOutcome'])->name('disputes.outcome');
            Route::post('/entitlements', [FinanceOpsController::class, 'gift'])->name('entitlements.gift');
            Route::get('/reconciliation', [FinanceOpsController::class, 'reconciliation'])->name('reconciliation');
            Route::get('/trial-balance', [FinanceOpsController::class, 'trialBalance'])->name('trial-balance');
        });
    });

    // Minimal pages (full screens come in wave 2 on the design system).
    Route::prefix('money')->name('money.')->group(function () {
        Route::get('/checkout/return', [MoneyPagesController::class, 'checkoutReturn'])->name('checkout.return');
        Route::get('/checkout/{order}', [MoneyPagesController::class, 'checkout'])->name('checkout.show');
        Route::post('/checkout/{order}/pay', [MoneyPagesController::class, 'pay'])->middleware('throttle:money-checkout')->name('checkout.pay');
        Route::post('/checkout/{order}/cancel', [MoneyPagesController::class, 'cancel'])->name('checkout.cancel');
        Route::get('/receipts/{receipt}', [MoneyPagesController::class, 'receipt'])->name('receipts.show');
        Route::get('/earnings', [MoneyPagesController::class, 'earnings'])->name('earnings');
        Route::get('/ops', [MoneyPagesController::class, 'ops'])->middleware('can:money.finance')->name('ops');
    });
});
